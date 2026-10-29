<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentScheduleWorkflow
{
    private const TRIGGERS = ['on_signing', 'before_issue', 'after_handover', 'fixed_date'];

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['installments' => $message]);
    }

    public function replaceDraftSchedule(int $debtId, array $installments, int $actorId, bool $allowUndatedLegacyTerms = false): void
    {
        DB::transaction(function () use ($debtId, $installments, $actorId, $allowUndatedLegacyTerms) {
            $debt = DB::table('debts')->where('id', $debtId)->lockForUpdate()->first();
            if (! $debt || $debt->direction !== 'receivable' || bccomp((string) $debt->amount, '0', 2) <= 0) {
                $this->fail('Chỉ lập lịch cho công nợ phải thu đang có giá trị dương.');
            }
            $existing = DB::table('payment_installments')->where('debt_id', $debtId)->lockForUpdate()->get();
            $hasAllocations = $existing->isNotEmpty() && DB::table('payment_allocations')
                ->whereIn('installment_id', $existing->pluck('id'))->exists();
            $order = $debt->order_id ? DB::table('orders')->where('id', $debt->order_id)->lockForUpdate()->first() : null;
            $contract = $order?->contract_id
                ? DB::table('contracts')->where('id', $order->contract_id)->lockForUpdate()->first()
                : ($order ? DB::table('contracts')->where('order_id', $order->id)->lockForUpdate()->first() : null);
            if ($hasAllocations || $contract?->status === 'signed') {
                $this->fail('Lịch đã phát sinh phân bổ hoặc hợp đồng đã ký; không thể ghi đè lịch sử.');
            }

            $normalized = $this->normalize($installments, $allowUndatedLegacyTerms);
            $total = '0.00';
            $required = '0.00';
            foreach ($normalized as $row) {
                $total = bcadd($total, $row['amount'], 2);
                if ($row['required_before_issue']) {
                    $required = bcadd($required, $row['amount'], 2);
                }
            }
            if (bccomp($total, (string) $debt->amount, 2) !== 0) {
                $this->fail('Tổng các đợt phải bằng giá trị công nợ hiện tại.');
            }
            if ($contract && bccomp($required, (string) $contract->deposit_required, 2) !== 0) {
                $this->fail('Tổng các đợt bắt buộc trước khi xuất phải bằng tiền cọc trên hợp đồng.');
            }
            if ($order?->fulfillment_method === 'pickup' && bccomp($required, (string) $debt->amount, 2) !== 0) {
                $this->fail('Đơn nhận tại kho phải thanh toán đủ trước khi xuất hàng.');
            }

            DB::table('payment_installments')->where('debt_id', $debtId)->delete();
            foreach ($normalized as $row) {
                DB::table('payment_installments')->insert($row + [
                    'debt_id' => $debtId, 'created_by' => $actorId,
                    'activated_at' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function saveContractDraftSchedule(
        int $contractId,
        array $installments,
        int $actorId,
        ?string $expectedDeposit = null,
        bool $allowUndatedLegacyTerms = false
    ): void {
        DB::transaction(function () use ($contractId, $installments, $actorId, $expectedDeposit, $allowUndatedLegacyTerms) {
            $contract = DB::table('contracts')->where('id', $contractId)->lockForUpdate()->first();
            if (! $contract || ! in_array($contract->status, ['draft', 'changes_requested'], true)) {
                $this->fail('Chỉ được sửa lịch thanh toán của hợp đồng chưa ký.');
            }
            $normalized = $this->normalize($installments, $allowUndatedLegacyTerms);
            $total = '0.00';
            $required = '0.00';
            foreach ($normalized as $row) {
                $total = bcadd($total, $row['amount'], 2);
                if ($row['required_before_issue']) {
                    $required = bcadd($required, $row['amount'], 2);
                }
            }
            $deposit = $expectedDeposit ?? (string) $contract->deposit_required;
            if (bccomp($total, (string) $contract->total, 2) !== 0
                || bccomp($required, $deposit, 2) !== 0
                || bccomp($deposit, '0', 2) < 0
                || bccomp($deposit, (string) $contract->total, 2) > 0) {
                $this->fail('Tổng lịch phải bằng giá trị hợp đồng và tổng đợt trước xuất phải bằng tiền cọc.');
            }
            DB::table('contracts')->where('id', $contractId)->update([
                'deposit_required' => $deposit,
                'payment_schedule_draft' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'payment_schedule_draft_by' => $actorId,
                'updated_at' => now(),
            ]);
        });
    }

    public function applyContractDraftSchedule(int $contractId, int $debtId, int $actorId): bool
    {
        $contract = DB::table('contracts')->where('id', $contractId)->first();
        if (! $contract || ! $contract->payment_schedule_draft) {
            return false;
        }
        $installments = is_array($contract->payment_schedule_draft)
            ? $contract->payment_schedule_draft
            : json_decode((string) $contract->payment_schedule_draft, true);
        if (! is_array($installments)) {
            $this->fail('Lịch nháp trên hợp đồng không đọc được; cần xử lý trước khi ký.');
        }
        $this->replaceDraftSchedule($debtId, $installments, $actorId);

        return true;
    }

    public function allocatePayment(int $paymentId): void
    {
        DB::transaction(function () use ($paymentId) {
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (! $payment) {
                $this->fail('Không tìm thấy giao dịch thu tiền để phân bổ.');
            }
            $this->allocateSource((int) $payment->debt_id, (string) $payment->amount, 'payment_id', $paymentId);
        });
    }

    public function allocateCustomerCredit(int $movementId): void
    {
        DB::transaction(function () use ($movementId) {
            $movement = DB::table('customer_credit_movements')->where('id', $movementId)->lockForUpdate()->first();
            if (! $movement || $movement->type !== 'offset' || ! $movement->debt_id) {
                $this->fail('Chỉ phân bổ được khoản cấn trừ ghi có có liên kết công nợ.');
            }
            $this->allocateSource((int) $movement->debt_id, (string) $movement->amount, 'credit_movement_id', $movementId);
        });
    }

    private function allocateSource(int $debtId, string $amount, string $sourceColumn, int $sourceId): void
    {
        $debt = DB::table('debts')->where('id', $debtId)->lockForUpdate()->first();
        if (! $debt || $debt->direction !== 'receivable' || bccomp($amount, '0', 2) <= 0) {
            $this->fail('Nguồn thu phải gắn với công nợ phải thu và có số tiền dương.');
        }
        $this->ensureLegacySchedule($debtId);
        $installments = DB::table('payment_installments')->where('debt_id', $debtId)->orderBy('sequence')->lockForUpdate()->get();
        $existing = DB::table('payment_allocations')->where($sourceColumn, $sourceId)->get();
        if ($existing->isNotEmpty()) {
            $allocated = $this->sumRows($existing, 'amount');
            if (bccomp($allocated, $amount, 2) === 0
                && $existing->every(fn ($allocation) => $installments->contains('id', $allocation->installment_id))) {
                return;
            }
            $this->fail('Giao dịch đã được phân bổ một phần hoặc không khớp số tiền nguồn.');
        }

        $capacity = '0.00';
        $state = [];
        foreach ($installments as $installment) {
            $principal = $this->effectivePrincipal($installment->id, (string) $installment->amount);
            $paid = $this->sumRows(DB::table('payment_allocations')->where('installment_id', $installment->id)->get(['amount']), 'amount');
            $remaining = bcsub($principal, $paid, 2);
            if (bccomp($remaining, '0', 2) < 0) {
                $remaining = '0.00';
            }
            $state[$installment->id] = $remaining;
            $capacity = bcadd($capacity, $remaining, 2);
        }
        if (bccomp($amount, $capacity, 2) > 0) {
            $this->fail('Số tiền nguồn vượt tổng nghĩa vụ chưa phân bổ trong lịch.');
        }

        $remainingSource = $amount;
        foreach ($installments as $installment) {
            if (bccomp($remainingSource, '0', 2) <= 0) {
                break;
            }
            $available = $state[$installment->id];
            $take = bccomp($remainingSource, $available, 2) < 0 ? $remainingSource : $available;
            if (bccomp($take, '0', 2) <= 0) {
                continue;
            }
            DB::table('payment_allocations')->insert([
                'installment_id' => $installment->id,
                'payment_id' => $sourceColumn === 'payment_id' ? $sourceId : null,
                'credit_movement_id' => $sourceColumn === 'credit_movement_id' ? $sourceId : null,
                'amount' => $take, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $remainingSource = bcsub($remainingSource, $take, 2);
        }
        if (bccomp($remainingSource, '0', 2) !== 0) {
            $this->fail('Không thể phân bổ đủ số tiền nguồn vào lịch thanh toán.');
        }
    }

    public function isReleaseEligible(int $orderId): bool
    {
        $debt = DB::table('debts')->where('order_id', $orderId)->where('direction', 'receivable')->first();
        if (! $debt) {
            return false;
        }
        $this->ensureLegacySchedule((int) $debt->id);
        $required = DB::table('payment_installments')->where('debt_id', $debt->id)
            ->where('required_before_issue', true)->orderBy('sequence')->get();
        foreach ($required as $installment) {
            $due = $this->effectivePrincipal($installment->id, (string) $installment->amount);
            $paid = $this->sumRows(DB::table('payment_allocations')->where('installment_id', $installment->id)->get(['amount']), 'amount');
            if (bccomp($paid, $due, 2) < 0) {
                return false;
            }
        }

        return true;
    }

    public function activateSigningTerms(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            $debt = DB::table('debts')->where('order_id', $orderId)->where('direction', 'receivable')->first();
            if (! $order || ! $debt) {
                return;
            }
            $signedAt = $order->contract_id ? DB::table('contracts')->where('id', $order->contract_id)->value('signed_at') : null;
            $base = $signedAt ? Carbon::parse($signedAt) : now();
            $this->activateTrigger((int) $debt->id, 'on_signing', $base);
        });
    }

    public function activateHandoverTerms(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order || $order->status !== 'delivered') {
                return;
            }
            $debt = DB::table('debts')->where('order_id', $orderId)->where('direction', 'receivable')->first();
            if (! $debt) {
                return;
            }
            $handoverAt = $order->fulfillment_method === 'pickup' ? $order->picked_up_at : null;
            if (! $handoverAt) {
                $handoverAt = DB::table('shipments')->where('order_id', $orderId)->where('status', 'delivered')
                    ->whereNotNull('delivered_at')->orderByDesc('delivered_at')->value('delivered_at');
            }
            if (! $handoverAt) {
                return;
            }
            $this->activateTrigger((int) $debt->id, 'after_handover', Carbon::parse($handoverAt));
        });
    }

    private function activateTrigger(int $debtId, string $trigger, Carbon $base): void
    {
        $rows = DB::table('payment_installments')->where('debt_id', $debtId)->where('trigger', $trigger)
            ->whereNull('activated_at')->orderBy('sequence')->lockForUpdate()->get();
        foreach ($rows as $row) {
            if ($trigger === 'after_handover' && $row->days_after_trigger === null) {
                continue;
            }
            $days = (int) ($row->days_after_trigger ?? 0);
            DB::table('payment_installments')->where('id', $row->id)->whereNull('activated_at')->update([
                'due_on' => $base->copy()->addDays($days)->toDateString(),
                'activated_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function adjustForReturn(int $returnId, int $debtId, int $actorId, string $amount, string $reason): void
    {
        DB::transaction(function () use ($returnId, $debtId, $actorId, $amount, $reason) {
            $return = DB::table('returns')->where('id', $returnId)->lockForUpdate()->first();
            $debt = DB::table('debts')->where('id', $debtId)->lockForUpdate()->first();
            if (! $return || ! $debt || $return->type !== 'return' || $return->status !== 'inspected'
                || $return->order_id !== $debt->order_id || $debt->direction !== 'receivable'
                || ! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0
                || trim($reason) === '' || DB::table('installment_adjustments')->where('return_id', $returnId)->exists()) {
                $this->fail('Điều chỉnh lịch theo phiếu trả hàng không hợp lệ hoặc đã được áp dụng.');
            }
            $this->ensureLegacySchedule($debtId);
            $installments = DB::table('payment_installments')->where('debt_id', $debtId)
                ->orderByDesc('sequence')->lockForUpdate()->get();
            $remaining = $amount;
            foreach ($installments as $installment) {
                if (bccomp($remaining, '0', 2) <= 0) {
                    break;
                }
                $principal = $this->effectivePrincipal($installment->id, (string) $installment->amount);
                $allocated = $this->sumRows(
                    DB::table('payment_allocations')->where('installment_id', $installment->id)->get(['amount']),
                    'amount'
                );
                $unpaid = bcsub($principal, $allocated, 2);
                $unpaid = bccomp($unpaid, '0', 2) > 0 ? $unpaid : '0.00';
                $take = bccomp($remaining, $unpaid, 2) < 0 ? $remaining : $unpaid;
                if (bccomp($take, '0', 2) <= 0) {
                    continue;
                }
                DB::table('installment_adjustments')->insert([
                    'installment_id' => $installment->id, 'return_id' => $returnId, 'recorded_by' => $actorId,
                    'amount' => bcmul($take, '-1', 2), 'reason' => trim($reason),
                    'adjusted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $remaining = bcsub($remaining, $take, 2);
            }
        });
    }

    public function summariesForDebts(array $debtIds): Collection
    {
        $rows = DB::table('payment_installments')->whereIn('debt_id', $debtIds)->orderBy('debt_id')->orderBy('sequence')->get();
        $summaries = [];
        foreach ($rows as $row) {
            $amount = $this->effectivePrincipal($row->id, (string) $row->amount);
            $paid = $this->sumRows(DB::table('payment_allocations')->where('installment_id', $row->id)->get(['amount']), 'amount');
            $remaining = bcsub($amount, $paid, 2);
            $remaining = bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
            $row->effective_amount = $amount;
            $row->paid_amount = $paid;
            $row->remaining_amount = $remaining;
            $row->needs_schedule_confirmation = $row->trigger === 'after_handover'
                && $row->days_after_trigger === null && $row->due_on === null && $row->activated_at === null;
            $row->display_status = bccomp($remaining, '0', 2) === 0 ? 'Đã đủ'
                : ($row->due_on && $row->due_on < now()->toDateString() ? 'Quá hạn'
                    : ($row->due_on && $row->due_on > now()->toDateString() ? 'Chưa đến hạn'
                        : (bccomp($paid, '0', 2) > 0 ? 'Thu một phần' : 'Chưa thu')));
            $summaries[$row->debt_id][] = $row;
        }

        return collect($summaries);
    }

    public function ensureLegacySchedule(int $debtId): void
    {
        if (DB::table('payment_installments')->where('debt_id', $debtId)->exists()) {
            return;
        }
        $debt = DB::table('debts')->where('id', $debtId)->first();
        if (! $debt || $debt->direction !== 'receivable' || bccomp((string) $debt->amount, '0', 2) <= 0) {
            return;
        }
        $order = $debt->order_id ? DB::table('orders')->where('id', $debt->order_id)->first() : null;
        $contract = $order?->contract_id
            ? DB::table('contracts')->where('id', $order->contract_id)->first()
            : ($order ? DB::table('contracts')->where('order_id', $order->id)->first() : null);
        $rows = [];
        if ($contract) {
            $preIssue = bccomp((string) $contract->deposit_required, (string) $debt->amount, 2) > 0
                ? (string) $debt->amount : (string) $contract->deposit_required;
            $postHandover = bcsub((string) $debt->amount, $preIssue, 2);
            if (bccomp($preIssue, '0', 2) > 0) {
                $rows[] = ['sequence' => 1, 'label' => 'Khoản thanh toán trước khi xuất hàng', 'amount' => $preIssue,
                    'due_on' => null, 'trigger' => 'before_issue', 'days_after_trigger' => null, 'required_before_issue' => true];
            }
            if (bccomp($postHandover, '0', 2) > 0) {
                $rows[] = ['sequence' => count($rows) + 1, 'label' => 'Khoản thanh toán còn lại theo hợp đồng', 'amount' => $postHandover,
                    'due_on' => null, 'trigger' => 'after_handover', 'days_after_trigger' => null, 'required_before_issue' => false];
            }
        } else {
            $rows[] = ['sequence' => 1, 'label' => $order?->fulfillment_method === 'pickup'
                ? 'Thanh toán đủ trước khi nhận hàng' : 'Công nợ phải thu',
                'amount' => (string) $debt->amount, 'due_on' => $debt->due_on,
                'trigger' => $order ? 'before_issue' : ($debt->due_on ? 'fixed_date' : 'on_signing'),
                'days_after_trigger' => null, 'required_before_issue' => (bool) $order];
        }
        foreach ($rows as $row) {
            DB::table('payment_installments')->insert($row + [
                'debt_id' => $debtId, 'created_by' => null, 'activated_at' => null,
                'created_at' => $debt->created_at ?? now(), 'updated_at' => now(),
            ]);
        }
    }

    private function effectivePrincipal(int $installmentId, string $amount): string
    {
        $adjustments = $this->sumRows(
            DB::table('installment_adjustments')->where('installment_id', $installmentId)->get(['amount']), 'amount'
        );
        $effective = bcadd($amount, $adjustments, 2);

        return bccomp($effective, '0', 2) > 0 ? $effective : '0.00';
    }

    private function sumRows(iterable $rows, string $column): string
    {
        $sum = '0.00';
        foreach ($rows as $row) {
            $sum = bcadd($sum, (string) $row->{$column}, 2);
        }

        return $sum;
    }

    private function normalize(array $installments, bool $allowUndatedLegacyTerms = false): array
    {
        if ($installments === []) {
            $this->fail('Lịch cần có ít nhất một đợt thanh toán.');
        }
        $normalized = [];
        $sequences = [];
        foreach ($installments as $row) {
            if (! is_array($row)) {
                $this->fail('Mỗi dòng lịch thanh toán phải là một tập trường hợp lệ.');
            }
            foreach (['sequence', 'label', 'amount', 'trigger', 'due_on', 'days_after_trigger', 'required_before_issue'] as $field) {
                if (isset($row[$field]) && ! is_scalar($row[$field])) {
                    $this->fail('Giá trị trong lịch thanh toán không hợp lệ.');
                }
            }
            $sequence = filter_var($row['sequence'] ?? null, FILTER_VALIDATE_INT);
            $label = trim((string) ($row['label'] ?? ''));
            $amount = (string) ($row['amount'] ?? '');
            $trigger = (string) ($row['trigger'] ?? '');
            $dueOn = $row['due_on'] ?? null;
            $days = $row['days_after_trigger'] ?? null;
            $requiredValue = $row['required_before_issue'] ?? false;
            if (! in_array($requiredValue, [true, false, 0, 1, '0', '1'], true)) {
                $this->fail('Cờ bắt buộc thanh toán trước khi xuất không hợp lệ.');
            }
            $required = filter_var($requiredValue, FILTER_VALIDATE_BOOLEAN);
            if (! $sequence || $sequence < 1 || isset($sequences[$sequence]) || $label === '' || mb_strlen($label) > 150
                || ! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0
                || ! in_array($trigger, self::TRIGGERS, true)) {
                $this->fail('Thông tin đợt thanh toán không hợp lệ; kiểm tra thứ tự, tên, số tiền và mốc.');
            }
            if ($trigger === 'fixed_date' && (! is_string($dueOn) || ! $this->isValidDate($dueOn))) {
                $this->fail('Đợt có mốc ngày cố định phải có ngày đến hạn hợp lệ.');
            }
            if ($trigger === 'after_handover' && (($days === null && ! $allowUndatedLegacyTerms)
                || ($days !== null && (filter_var($days, FILTER_VALIDATE_INT) === false || (int) $days < 0)))) {
                $this->fail('Đợt sau bàn giao cần số ngày không âm tính từ ngày bàn giao.');
            }
            if ($trigger === 'after_handover' && $required) {
                $this->fail('Đợt sau bàn giao không thể đồng thời là điều kiện trước khi xuất.');
            }
            if ($trigger !== 'fixed_date') {
                $dueOn = null;
            }
            if ($trigger !== 'after_handover' && $trigger !== 'on_signing') {
                $days = null;
            }
            $sequences[$sequence] = true;
            $normalized[] = [
                'sequence' => $sequence, 'label' => $label, 'amount' => $amount,
                'due_on' => $dueOn, 'trigger' => $trigger,
                'days_after_trigger' => $days === null ? null : (int) $days,
                'required_before_issue' => $required,
            ];
        }
        usort($normalized, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

        return $normalized;
    }

    private function isValidDate(string $date): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        try {
            return Carbon::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') === $date;
        } catch (\Throwable) {
            return false;
        }
    }
}
