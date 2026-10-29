<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'idempotency_key')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('idempotency_key', 64)->nullable();
            });
        }
        if (! Schema::hasIndex('payments', ['idempotency_key'], 'unique')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unique('idempotency_key');
            });
        }
        if (! Schema::hasColumn('contracts', 'payment_schedule_draft')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->json('payment_schedule_draft')->nullable();
            });
        }
        if (! Schema::hasColumn('contracts', 'payment_schedule_draft_by')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->foreignId('payment_schedule_draft_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('returns', 'completed_at')) {
            Schema::table('returns', function (Blueprint $table) {
                $table->timestamp('completed_at', 6)->nullable();
            });
        }

        if (! Schema::hasTable('payment_installments')) {
            Schema::create('payment_installments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('sequence');
                $table->string('label', 150);
                $table->decimal('amount', 18, 2);
                $table->date('due_on')->nullable();
                $table->string('trigger', 30);
                $table->unsignedInteger('days_after_trigger')->nullable();
                $table->boolean('required_before_issue')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('activated_at')->nullable();
                $table->timestamps();
                $table->unique(['debt_id', 'sequence']);
            });
        }

        if (! Schema::hasTable('payment_allocations')) {
            Schema::create('payment_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('installment_id')->constrained('payment_installments')->cascadeOnDelete();
                $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('credit_movement_id')->nullable()->constrained('customer_credit_movements')->restrictOnDelete();
                $table->decimal('amount', 18, 2);
                $table->timestamps();
                $table->unique(['installment_id', 'payment_id']);
                $table->unique(['installment_id', 'credit_movement_id']);
            });
        }

        if (! Schema::hasTable('installment_adjustments')) {
            Schema::create('installment_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('installment_id')->constrained('payment_installments')->restrictOnDelete();
                $table->foreignId('return_id')->constrained('returns')->restrictOnDelete();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('amount', 18, 2);
                $table->text('reason');
                $table->timestamp('adjusted_at');
                $table->timestamps();
                $table->unique(['return_id', 'installment_id']);
            });
        }

        $this->ensureUniqueIndex('payment_installments', ['debt_id', 'sequence']);
        $this->ensureUniqueIndex('payment_allocations', ['installment_id', 'payment_id']);
        $this->ensureUniqueIndex('payment_allocations', ['installment_id', 'credit_movement_id']);
        $this->ensureUniqueIndex('installment_adjustments', ['return_id', 'installment_id']);

        $this->ensureForeignKey('contracts', 'payment_schedule_draft_by', 'users', 'set null');
        $this->ensureForeignKey('payment_installments', 'debt_id', 'debts', 'cascade');
        $this->ensureForeignKey('payment_installments', 'created_by', 'users', 'set null');
        $this->ensureForeignKey('payment_allocations', 'installment_id', 'payment_installments', 'cascade');
        $this->ensureForeignKey('payment_allocations', 'payment_id', 'payments', 'restrict');
        $this->ensureForeignKey('payment_allocations', 'credit_movement_id', 'customer_credit_movements', 'restrict');
        $this->ensureForeignKey('installment_adjustments', 'installment_id', 'payment_installments', 'restrict');
        $this->ensureForeignKey('installment_adjustments', 'return_id', 'returns', 'restrict');
        $this->ensureForeignKey('installment_adjustments', 'recorded_by', 'users', 'set null');

        $this->backfillLegacyReceivables();
    }

    private function ensureUniqueIndex(string $tableName, array $columns): void
    {
        if (Schema::hasIndex($tableName, $columns, 'unique')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns) {
            $table->unique($columns);
        });
    }

    private function ensureForeignKey(string $tableName, string $column, string $referencedTable, string $onDelete): void
    {
        if (Schema::hasForeignKey($tableName, [$column])) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable, $onDelete) {
            $table->foreign($column)->references('id')->on($referencedTable)->onDelete($onDelete);
        });
    }

    private function backfillLegacyReceivables(): void
    {
        DB::table('debts')->where('direction', 'receivable')->where('amount', '>', 0)
            ->orderBy('id')->chunkById(100, function ($debts) {
                foreach ($debts as $debt) {
                    DB::transaction(function () use ($debt) {
                        $returns = $this->legacyReturnsForDebt($debt);
                        $this->assertLegacyReturnOrderIsUnambiguous($debt, $returns);
                        $existing = DB::table('payment_installments')->where('debt_id', $debt->id)->get();
                        if ($existing->isNotEmpty()) {
                            if ($this->isDebtBackfillComplete($debt, $existing)) {
                                return;
                            }
                            throw new RuntimeException('Backfill công nợ '.$debt->id.' chưa hoàn chỉnh; cần đối soát thủ công, không tự ghi đè lịch sử.');
                        }

                        $principal = (string) $debt->amount;
                        foreach ($returns as $return) {
                            $principal = bcadd($principal, (string) $return->credit_amount, 2);
                        }

                        $order = $debt->order_id ? DB::table('orders')->where('id', $debt->order_id)->first() : null;
                        $contract = $order?->contract_id
                            ? DB::table('contracts')->where('id', $order->contract_id)->first()
                            : ($order ? DB::table('contracts')->where('order_id', $order->id)->first() : null);
                        $rows = [];
                        if ($order && $contract) {
                            $deposit = bccomp((string) $contract->deposit_required, $principal, 2) > 0
                                ? $principal : (string) $contract->deposit_required;
                            $remainder = bcsub($principal, $deposit, 2);
                            if (bccomp($deposit, '0', 2) > 0) {
                                $rows[] = [
                                    'sequence' => 1, 'label' => 'Khoản thanh toán trước khi xuất hàng',
                                    'amount' => $deposit, 'due_on' => null, 'trigger' => 'before_issue',
                                    'days_after_trigger' => null, 'required_before_issue' => true,
                                ];
                            }
                            if (bccomp($remainder, '0', 2) > 0) {
                                $rows[] = [
                                    'sequence' => count($rows) + 1, 'label' => 'Khoản thanh toán còn lại theo hợp đồng',
                                    'amount' => $remainder, 'due_on' => null, 'trigger' => 'after_handover',
                                    'days_after_trigger' => null, 'required_before_issue' => false,
                                ];
                            }
                        } elseif ($order) {
                            $rows[] = [
                                'sequence' => 1, 'label' => $order->fulfillment_method === 'pickup'
                                    ? 'Thanh toán đủ trước khi nhận hàng' : 'Thanh toán trước khi xuất hàng',
                                'amount' => $principal, 'due_on' => null, 'trigger' => 'before_issue',
                                'days_after_trigger' => null, 'required_before_issue' => true,
                            ];
                        } else {
                            $rows[] = [
                                'sequence' => 1, 'label' => 'Công nợ phải thu', 'amount' => $principal,
                                'due_on' => $debt->due_on, 'trigger' => $debt->due_on ? 'fixed_date' : 'on_signing',
                                'days_after_trigger' => null, 'required_before_issue' => false,
                            ];
                        }

                        foreach ($rows as $row) {
                            $row['debt_id'] = $debt->id;
                            $row['created_at'] = $debt->created_at ?? now();
                            $row['updated_at'] = $debt->updated_at ?? now();
                            $row['activated_at'] = null;
                            DB::table('payment_installments')->insert($row);
                        }

                        $installments = DB::table('payment_installments')->where('debt_id', $debt->id)->orderBy('sequence')->get();
                        $sources = collect();
                        $sources = $sources->concat(DB::table('payments')->where('debt_id', $debt->id)
                            ->get()->map(fn ($row) => (object) ['kind' => 'payment', 'id' => $row->id, 'amount' => $row->amount, 'at' => $row->paid_at]));
                        $sources = $sources->concat(DB::table('customer_credit_movements')->where('debt_id', $debt->id)
                            ->where('type', 'offset')->get()->map(fn ($row) => (object) ['kind' => 'credit', 'id' => $row->id, 'amount' => $row->amount, 'at' => $row->created_at]));
                        $sources = $sources->sortBy([['at', 'asc'], ['id', 'asc']]);
                        $used = array_fill_keys($installments->pluck('id')->all(), '0.00');
                        foreach ($sources as $source) {
                            $remaining = (string) $source->amount;
                            foreach ($installments as $installment) {
                                if (bccomp($remaining, '0', 2) <= 0) {
                                    break;
                                }
                                $capacity = bcsub((string) $installment->amount, $used[$installment->id], 2);
                                $take = bccomp($remaining, $capacity, 2) < 0 ? $remaining : $capacity;
                                if (bccomp($take, '0', 2) > 0) {
                                    DB::table('payment_allocations')->insert([
                                        'installment_id' => $installment->id,
                                        'payment_id' => $source->kind === 'payment' ? $source->id : null,
                                        'credit_movement_id' => $source->kind === 'credit' ? $source->id : null,
                                        'amount' => $take, 'created_at' => $source->at ?? now(), 'updated_at' => $source->at ?? now(),
                                    ]);
                                    $used[$installment->id] = bcadd($used[$installment->id], $take, 2);
                                    $remaining = bcsub($remaining, $take, 2);
                                }
                            }
                            if (bccomp($remaining, '0', 2) !== 0) {
                                throw new RuntimeException('Không thể phân bổ đủ giao dịch lịch sử '.$source->id.' của công nợ '.$debt->id.'.');
                            }
                        }

                        foreach ($returns as $return) {
                            $remaining = (string) $return->credit_amount;
                            foreach ($installments->sortByDesc('sequence') as $installment) {
                                if (bccomp($remaining, '0', 2) <= 0) {
                                    break;
                                }
                                $prior = (string) DB::table('installment_adjustments')->where('installment_id', $installment->id)
                                    ->sum('amount');
                                $alreadyReduced = bccomp($prior, '0', 2) < 0 ? bcmul($prior, '-1', 2) : '0.00';
                                $allocated = (string) DB::table('payment_allocations')->where('installment_id', $installment->id)
                                    ->sum('amount');
                                $unpaid = bcsub(bcsub((string) $installment->amount, $alreadyReduced, 2), $allocated, 2);
                                $unpaid = bccomp($unpaid, '0', 2) > 0 ? $unpaid : '0.00';
                                $take = bccomp($remaining, $unpaid, 2) < 0 ? $remaining : $unpaid;
                                if (bccomp($take, '0', 2) > 0) {
                                    DB::table('installment_adjustments')->insert([
                                        'installment_id' => $installment->id, 'return_id' => $return->id,
                                        'recorded_by' => $return->processed_by,
                                        'amount' => bcmul($take, '-1', 2),
                                        'reason' => 'Điều chỉnh giảm theo phiếu trả hàng '.$return->code,
                                        'adjusted_at' => $return->updated_at ?? now(),
                                        'created_at' => $return->updated_at ?? now(), 'updated_at' => $return->updated_at ?? now(),
                                    ]);
                                    $remaining = bcsub($remaining, $take, 2);
                                }
                            }
                        }
                    });
                }
            });
    }

    private function isDebtBackfillComplete(object $debt, Collection $installments): bool
    {
        $returns = $this->legacyReturnsForDebt($debt);
        $this->assertLegacyReturnOrderIsUnambiguous($debt, $returns);
        $principal = (string) $debt->amount;
        foreach ($returns as $return) {
            $principal = bcadd($principal, (string) $return->credit_amount, 2);
        }
        if (bccomp($this->sumMoney($installments, 'amount'), $principal, 2) !== 0) {
            return false;
        }

        $installmentIds = $installments->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sources = DB::table('payments')->where('debt_id', $debt->id)->get(['id', 'amount']);
        foreach ($sources as $source) {
            $allocations = DB::table('payment_allocations')->where('payment_id', $source->id)->get(['amount', 'installment_id']);
            if (bccomp($this->sumMoney($allocations), (string) $source->amount, 2) !== 0
                || $allocations->contains(fn ($allocation) => ! in_array((int) $allocation->installment_id, $installmentIds, true))) {
                return false;
            }
        }
        $movements = DB::table('customer_credit_movements')->where('debt_id', $debt->id)
            ->where('type', 'offset')->get(['id', 'amount']);
        foreach ($movements as $movement) {
            $allocations = DB::table('payment_allocations')->where('credit_movement_id', $movement->id)->get(['amount', 'installment_id']);
            if (bccomp($this->sumMoney($allocations), (string) $movement->amount, 2) !== 0
                || $allocations->contains(fn ($allocation) => ! in_array((int) $allocation->installment_id, $installmentIds, true))) {
                return false;
            }
        }

        $unpaidByInstallment = [];
        foreach ($installments as $installment) {
            $allocated = (string) DB::table('payment_allocations')->where('installment_id', $installment->id)->sum('amount');
            $unpaid = bcsub((string) $installment->amount, $allocated, 2);
            $unpaidByInstallment[$installment->id] = bccomp($unpaid, '0', 2) > 0 ? $unpaid : '0.00';
        }

        $expectedAdjustmentTotal = '0.00';
        foreach ($returns as $return) {
            $remaining = (string) $return->credit_amount;
            $expectedAdjustments = [];
            foreach ($installments->sortByDesc('sequence') as $installment) {
                if (bccomp($remaining, '0', 2) <= 0) {
                    break;
                }
                $take = bccomp($remaining, $unpaidByInstallment[$installment->id], 2) < 0
                    ? $remaining : $unpaidByInstallment[$installment->id];
                if (bccomp($take, '0', 2) > 0) {
                    $expectedAdjustments[$installment->id] = bcmul($take, '-1', 2);
                    $unpaidByInstallment[$installment->id] = bcsub($unpaidByInstallment[$installment->id], $take, 2);
                    $remaining = bcsub($remaining, $take, 2);
                    $expectedAdjustmentTotal = bcsub($expectedAdjustmentTotal, $take, 2);
                }
            }

            $actualAdjustments = DB::table('installment_adjustments')->where('return_id', $return->id)
                ->get(['installment_id', 'amount']);
            if ($actualAdjustments->count() !== count($expectedAdjustments)) {
                return false;
            }
            foreach ($expectedAdjustments as $installmentId => $amount) {
                $actual = $actualAdjustments->firstWhere('installment_id', $installmentId);
                if (! $actual || bccomp((string) $actual->amount, $amount, 2) !== 0) {
                    return false;
                }
            }
        }

        $adjustments = DB::table('installment_adjustments')
            ->whereIn('installment_id', $installments->pluck('id'))->get(['amount']);

        return bccomp($this->sumMoney($adjustments), $expectedAdjustmentTotal, 2) === 0;
    }

    private function legacyReturnsForDebt(object $debt): Collection
    {
        return $debt->order_id
            ? DB::table('returns')->where('order_id', $debt->order_id)->where('type', 'return')
                ->where('status', 'completed')->where('credit_amount', '>', 0)
                ->orderByRaw('COALESCE(completed_at, updated_at)')->orderBy('id')->get()
            : collect();
    }

    private function assertLegacyReturnOrderIsUnambiguous(object $debt, Collection $returns): void
    {
        if ($returns->count() < 2) {
            return;
        }

        $principal = (string) $debt->amount;
        foreach ($returns as $return) {
            $principal = bcadd($principal, (string) $return->credit_amount, 2);
        }
        $collected = $this->sumMoney(DB::table('payments')->where('debt_id', $debt->id)->get(['amount']));
        $offsets = $this->sumMoney(DB::table('customer_credit_movements')->where('debt_id', $debt->id)
            ->where('type', 'offset')->get(['amount']));
        $unpaid = bcsub(bcsub($principal, $collected, 2), $offsets, 2);
        if (bccomp($unpaid, '0', 2) <= 0) {
            return;
        }

        foreach ($returns->groupBy(fn ($return) => substr((string) ($return->completed_at ?? $return->updated_at), 0, 19)) as $sameSecond) {
            if ($sameSecond->count() < 2) {
                continue;
            }

            $hasSecondPrecisionFallback = $sameSecond->contains(fn ($return) => $return->completed_at === null);
            $completionTimes = $sameSecond->pluck('completed_at')->filter()->map(fn ($time) => (string) $time);
            $hasDuplicatePreciseTime = $completionTimes->count() !== $completionTimes->unique()->count();
            if ($hasSecondPrecisionFallback || $hasDuplicatePreciseTime) {
                throw new RuntimeException('Thứ tự hoàn tất các phiếu trả lịch sử của công nợ '.$debt->id.' không xác định; cần đối soát thủ công, không tự suy đoán.');
            }
        }
    }

    private function sumMoney(iterable $rows, string $column = 'amount'): string
    {
        $sum = '0.00';
        foreach ($rows as $row) {
            $sum = bcadd($sum, (string) $row->{$column}, 2);
        }

        return $sum;
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_adjustments');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_installments');
        if (Schema::hasColumn('payments', 'idempotency_key')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            });
        }
        if (Schema::hasColumn('contracts', 'payment_schedule_draft_by')) {
            Schema::table('contracts', fn (Blueprint $table) => $table->dropConstrainedForeignId('payment_schedule_draft_by'));
        }
        if (Schema::hasColumn('contracts', 'payment_schedule_draft')) {
            Schema::table('contracts', fn (Blueprint $table) => $table->dropColumn('payment_schedule_draft'));
        }
        if (Schema::hasColumn('returns', 'completed_at')) {
            Schema::table('returns', fn (Blueprint $table) => $table->dropColumn('completed_at'));
        }
    }
};
