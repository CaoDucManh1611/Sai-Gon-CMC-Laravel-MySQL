<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupplierDebtConfirmationWorkflow
{
    private const ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function record(
        int $debtId,
        int $accountantId,
        ?int $paymentId,
        string $contactName,
        string $confirmedOn,
        string $statement,
        UploadedFile $attachment,
    ): int {
        $this->assertAccountingActor($accountantId);
        if (trim($contactName) === '' || mb_strlen(trim($contactName)) > 150 || trim($statement) === '') {
            $this->fail('statement', 'Cần ghi người liên hệ và nội dung xác nhận của nhà cung cấp.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $confirmedOn)) {
            $this->fail('confirmed_on', 'Ngày xác nhận không hợp lệ.');
        }
        try {
            $confirmedAt = CarbonImmutable::createFromFormat('!Y-m-d', $confirmedOn);
        } catch (\Throwable) {
            $this->fail('confirmed_on', 'Ngày xác nhận không hợp lệ.');
        }
        if (! $confirmedAt || $confirmedAt->toDateString() !== $confirmedOn) {
            $this->fail('confirmed_on', 'Ngày xác nhận không hợp lệ.');
        }
        if ($confirmedAt->isFuture()) {
            $this->fail('confirmed_on', 'Ngày xác nhận không được ở tương lai.');
        }
        $mime = $attachment->getMimeType();
        if (! $attachment->isValid() || $attachment->getSize() > 10 * 1024 * 1024 || ! in_array($mime, self::ALLOWED_MIMES, true)) {
            $this->fail('attachment', 'Tệp chứng từ phải là PDF, JPEG hoặc PNG và không lớn hơn 10 MB.');
        }

        $storedPath = null;
        try {
            return DB::transaction(function () use ($debtId, $accountantId, $paymentId, $contactName, $confirmedOn, $statement, $attachment, $mime, &$storedPath) {
                $debt = DB::table('debts')->where('id', $debtId)->lockForUpdate()->first();
                if (! $debt || $debt->direction !== 'payable' || ! $debt->supplier_id) {
                    $this->fail('debt_id', 'Chỉ ghi nhận xác nhận cho khoản phải trả nhà cung cấp.');
                }
                if ($paymentId !== null && ! DB::table('payments')->where('id', $paymentId)->where('debt_id', $debtId)->exists()) {
                    $this->fail('payment_id', 'Khoản thanh toán không thuộc công nợ đã chọn.');
                }

                $storedPath = Storage::disk('local')->putFile('supplier-confirmations', $attachment);
                if (! $storedPath) {
                    $this->fail('attachment', 'Không thể lưu tệp chứng từ. Vui lòng thử lại.');
                }
                $id = DB::table('supplier_debt_confirmations')->insertGetId([
                    'debt_id' => $debtId, 'supplier_id' => $debt->supplier_id, 'payment_id' => $paymentId,
                    'recorded_by' => $accountantId, 'contact_name' => trim($contactName), 'confirmed_on' => $confirmedOn,
                    'statement' => trim($statement), 'file_path' => $storedPath,
                    'file_name' => mb_substr(basename(str_replace('\\', '/', $attachment->getClientOriginalName())), 0, 255),
                    'file_mime' => $mime, 'file_size' => (int) $attachment->getSize(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $id;
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    private function assertAccountingActor(int $actorId): void
    {
        $role = DB::table('users')->where('id', $actorId)->value('role');
        if (! in_array($role, ['accounting', 'admin'], true)) {
            $this->fail('workflow', 'Chỉ kế toán hoặc quản trị viên được ghi nhận chứng từ xác nhận nhà cung cấp.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
