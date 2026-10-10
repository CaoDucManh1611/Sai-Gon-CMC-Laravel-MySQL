<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogWorkflow
{
    public function propose(string $type, ?int $productId, array $data, int $staffId): int
    {
        if (! in_array($type, ['product_create', 'product_update', 'category_create', 'category_update'], true)) {
            throw ValidationException::withMessages(['type' => 'Loại đề xuất không hợp lệ.']);
        }
        if (str_ends_with($type, 'update') && ! $productId) {
            throw ValidationException::withMessages(['product_id' => 'Cần chọn bản ghi cần sửa.']);
        }

        return DB::table('catalog_changes')->insertGetId([
            'product_id' => str_starts_with($type, 'product_') && $type === 'product_update' ? $productId : null,
            'requested_by' => $staffId, 'type' => $type,
            'proposed_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function review(int $changeId, int $managerId, bool $approve, ?string $note = null): void
    {
        DB::transaction(function () use ($changeId, $managerId, $approve, $note) {
            $change = DB::table('catalog_changes')->where('id', $changeId)->lockForUpdate()->first();
            if (! $change || $change->status !== 'pending') {
                throw ValidationException::withMessages(['workflow' => 'Đề xuất không còn chờ duyệt.']);
            }
            if ($approve) {
                $data = json_decode($change->proposed_data, true, flags: JSON_THROW_ON_ERROR);
                if ($change->type === 'product_create') {
                    $data['created_at'] = now();
                    $data['updated_at'] = now();
                    $productId = DB::table('products')->insertGetId($data);
                    DB::table('price_history')->insert([
                        'product_id' => $productId, 'price' => $data['price'],
                        'effective_date' => now()->toDateString(), 'approved_by' => $managerId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('catalog_changes')->where('id', $changeId)->update(['product_id' => $productId]);
                } elseif ($change->type === 'product_update') {
                    $current = DB::table('products')->where('id', $change->product_id)->first();
                    if (! $current) {
                        throw ValidationException::withMessages(['workflow' => 'Sản phẩm không còn tồn tại.']);
                    }
                    $data['updated_at'] = now();
                    DB::table('products')->where('id', $change->product_id)->update($data);
                    if (isset($data['price']) && bccomp((string) $current->price, (string) $data['price'], 2) !== 0) {
                        DB::table('price_history')->insert([
                            'product_id' => $change->product_id, 'price' => $data['price'],
                            'effective_date' => now()->toDateString(), 'approved_by' => $managerId,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                } elseif ($change->type === 'category_create') {
                    DB::table('categories')->insert($data + ['created_at' => now(), 'updated_at' => now()]);
                } elseif ($change->type === 'category_update') {
                    DB::table('categories')->where('id', $data['id'])->update([
                        'name' => $data['name'], 'description' => $data['description'] ?? null,
                        'active' => $data['active'] ?? true, 'updated_at' => now(),
                    ]);
                }
            }
            DB::table('catalog_changes')->where('id', $changeId)->update([
                'status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $managerId,
                'review_note' => $note, 'updated_at' => now(),
            ]);
        });
    }
}
