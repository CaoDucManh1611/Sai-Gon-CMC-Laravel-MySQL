<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DebtReconciliationWorkflow
{
    public function createSnapshot(int $customerId, string $asOfDate, int $accountantId, ?string $periodStart = null): int
    {
        $this->assertAccountingActor($accountantId);
        $customer = DB::table('users')->where('id', $customerId)->where('role', 'customer')->first();
        if (! $customer) {
            $this->fail('customer_id', 'Không tìm thấy tài khoản khách hàng hợp lệ.');
        }

        $asOf = $this->parseDate($asOfDate, 'as_of_date', 'Ngày đối chiếu không hợp lệ.');
        $start = $this->parseDate($periodStart ?: $asOf->startOfMonth()->toDateString(), 'period_start', 'Ngày bắt đầu kỳ không hợp lệ.');
        if ($asOf->isFuture() || $start->gt($asOf)) {
            $this->fail('as_of_date', 'Ngày chốt phải không vượt quá hôm nay và ngày bắt đầu kỳ không được sau ngày chốt.');
        }

        $createdAt = now();
        $cutoff = $asOf->isToday() ? CarbonImmutable::instance($createdAt) : $asOf->endOfDay();
        $periodStartAt = $start->startOfDay();
        $openingCutoff = $periodStartAt->subMicrosecond();

        return DB::transaction(function () use ($customerId, $accountantId, $asOf, $start, $cutoff, $periodStartAt, $openingCutoff, $createdAt) {
            $lockedCustomer = DB::table('users')->where('id', $customerId)->where('role', 'customer')->lockForUpdate()->first();
            if (! $lockedCustomer) {
                $this->fail('customer_id', 'Không tìm thấy tài khoản khách hàng hợp lệ.');
            }

            $debts = DB::table('debts')->leftJoin('orders', 'orders.id', '=', 'debts.order_id')
                ->leftJoin('contracts', 'contracts.id', '=', 'orders.contract_id')
                ->where('debts.customer_id', $customerId)->where('debts.direction', 'receivable')
                ->where('debts.created_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))
                ->select('debts.*', 'orders.code as order_code', 'orders.total as order_total', 'orders.status as order_status',
                    'orders.requires_contract', 'orders.reviewed_at', 'orders.cancelled_at as order_cancelled_at',
                    'orders.updated_at as order_updated_at', 'contracts.status as contract_status', 'contracts.signed_at',
                    'contracts.cancelled_at as contract_cancelled_at')
                ->orderBy('debts.id')->lockForUpdate()->get();

            $this->assertNoUntrackedOrderEdits($debts, $periodStartAt, $cutoff);

            $rows = [];
            $totals = [
                'opening_balance' => '0.00', 'period_charges' => '0.00', 'period_returns' => '0.00',
                'period_collected' => '0.00', 'period_credit_issued' => '0.00',
                'period_offsets' => '0.00', 'period_cancellations' => '0.00',
                'closing_balance' => '0.00',
            ];

            foreach ($debts as $debt) {
                $timeline = $this->timelineForDebt($debt, $cutoff);
                if (! $timeline || $timeline['effective_at']->gt($cutoff)) {
                    continue;
                }

                $opening = $this->balanceThrough($timeline['principal'], $timeline['events'], $openingCutoff, false);
                $closing = $this->balanceThrough($timeline['principal'], $timeline['events'], $cutoff, true);
                $periodTotals = $this->periodTotals($timeline['principal'], $timeline['events'], $periodStartAt, $cutoff);
                if ($opening === '0.00' && $closing === '0.00' && $periodTotals['period_charges'] === '0.00'
                    && $periodTotals['period_collected'] === '0.00' && $periodTotals['period_returns'] === '0.00'
                    && $periodTotals['period_credit_issued'] === '0.00' && $periodTotals['period_offsets'] === '0.00') {
                    continue;
                }

                $row = [
                    'debt_id' => (int) $debt->id,
                    'debt_code' => $debt->code,
                    'order_code' => $debt->order_code,
                    'effective_at' => $timeline['effective_at']->format('Y-m-d H:i:s'),
                    'opening_balance' => $opening,
                    'period_charges' => $periodTotals['period_charges'],
                    'period_returns' => $periodTotals['period_returns'],
                    'period_collected' => $periodTotals['period_collected'],
                    'period_credit_issued' => $periodTotals['period_credit_issued'],
                    'period_offsets' => $periodTotals['period_offsets'],
                    'period_cancellations' => $periodTotals['period_cancellations'],
                    'closing_balance' => $closing,
                    'transactions' => $this->visibleEvents($timeline['events'], $periodStartAt, $cutoff),
                ];
                $rows[] = $row;
                foreach ($totals as $key => $value) {
                    $totals[$key] = bcadd($value, $key === 'opening_balance' ? $opening : ($key === 'closing_balance' ? $closing : $periodTotals[$key]), 2);
                }
            }

            $snapshot = [
                'customer' => ['id' => $customerId, 'name' => $lockedCustomer->name, 'code' => $lockedCustomer->username],
                'period_start' => $start->toDateString(),
                'as_of_date' => $asOf->toDateString(),
                'snapshot_at' => $createdAt->format('Y-m-d H:i:s.u'),
                'currency' => 'VND',
                'totals' => $totals,
                'debts' => $rows,
            ];
            $content = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $previous = DB::table('debt_reconciliations')->where('customer_id', $customerId)->orderByDesc('version_number')->first();
            $version = ((int) ($previous->version_number ?? 0)) + 1;
            $id = DB::table('debt_reconciliations')->insertGetId([
                'customer_id' => $customerId, 'created_by' => $accountantId,
                'previous_id' => $previous?->id, 'version_number' => $version,
                'period_start' => $start->toDateString(), 'as_of_date' => $asOf->toDateString(),
                ...$totals, 'content_snapshot' => $content, 'snapshot_hash' => hash('sha256', $content),
                'status' => 'draft', 'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);

            return $id;
        });
    }

    public function send(int $reconciliationId, int $accountantId): void
    {
        $this->assertAccountingActor($accountantId);
        DB::transaction(function () use ($reconciliationId) {
            $row = DB::table('debt_reconciliations')->where('id', $reconciliationId)->lockForUpdate()->first();
            if (! $row || $row->status !== 'draft') {
                $this->fail('workflow', 'Chỉ gửi được bản đối chiếu đang ở trạng thái nháp.');
            }
            $this->assertSnapshotIntegrity($row);
            $sentAt = now();
            DB::table('debt_reconciliations')->where('id', $reconciliationId)->update([
                'status' => 'sent', 'sent_at' => $sentAt, 'updated_at' => $sentAt,
            ]);
        });
    }

    public function respond(int $reconciliationId, int $customerId, string $decision, ?string $message = null): void
    {
        DB::transaction(function () use ($reconciliationId, $customerId, $decision, $message) {
            $row = DB::table('debt_reconciliations')->where('id', $reconciliationId)
                ->where('customer_id', $customerId)->lockForUpdate()->first();
            if (! $row) {
                abort(404);
            }
            $this->assertSnapshotIntegrity($row);
            if (! in_array($decision, ['confirm', 'dispute'], true) || trim((string) $message) === '' && $decision === 'dispute') {
                $this->fail('message', 'Khi báo chênh lệch, vui lòng mô tả khoản chưa khớp.');
            }
            $confirmingAfterResolution = $row->status === 'resolved' && $decision === 'confirm' && ! $row->customer_confirmed_at;
            if ($row->status !== 'sent' && ! $confirmingAfterResolution) {
                $this->fail('workflow', 'Hồ sơ đã đóng hoặc chưa được gửi; hãy phản hồi bản đối chiếu mới nhất.');
            }

            $confirmedAt = $decision === 'confirm' ? now() : null;
            DB::table('debt_reconciliation_messages')->insert([
                'reconciliation_id' => $reconciliationId, 'author_id' => $customerId,
                'kind' => $decision === 'confirm' ? 'customer_confirmation' : 'customer_dispute',
                'content' => trim((string) $message) !== '' ? trim((string) $message) : null,
                'created_at' => now(),
            ]);
            DB::table('debt_reconciliations')->where('id', $reconciliationId)->update([
                'status' => $row->status === 'sent' ? ($decision === 'confirm' ? 'confirmed' : 'disputed') : 'resolved',
                'customer_confirmed_at' => $confirmedAt ?? $row->customer_confirmed_at,
                'updated_at' => now(),
            ]);
        });
    }

    public function resolve(int $reconciliationId, int $accountantId, string $message): void
    {
        $this->assertAccountingActor($accountantId);
        if (trim($message) === '') {
            $this->fail('message', 'Cần ghi cách kế toán xử lý chênh lệch.');
        }
        DB::transaction(function () use ($reconciliationId, $accountantId, $message) {
            $row = DB::table('debt_reconciliations')->where('id', $reconciliationId)->lockForUpdate()->first();
            if (! $row || $row->status !== 'disputed') {
                $this->fail('workflow', 'Chỉ xử lý được hồ sơ khách đã báo chênh lệch.');
            }
            $this->assertSnapshotIntegrity($row);
            DB::table('debt_reconciliation_messages')->insert([
                'reconciliation_id' => $reconciliationId, 'author_id' => $accountantId,
                'kind' => 'accounting_resolution', 'content' => trim($message), 'created_at' => now(),
            ]);
            DB::table('debt_reconciliations')->where('id', $reconciliationId)->update([
                'status' => 'resolved', 'updated_at' => now(),
            ]);
        });
    }

    public function assertSnapshotIntegrity(object $reconciliation): void
    {
        if (! hash_equals((string) $reconciliation->snapshot_hash, hash('sha256', (string) $reconciliation->content_snapshot))) {
            abort(409, 'Bản snapshot đối chiếu không khớp mã kiểm tra; vui lòng lập bản đối chiếu mới.');
        }
    }

    private function timelineForDebt(object $debt, CarbonImmutable $cutoff): ?array
    {
        $effectiveAt = CarbonImmutable::parse($debt->created_at);
        $cancelledAt = null;
        if ($debt->order_id) {
            $reviewedAt = $debt->reviewed_at ? CarbonImmutable::parse($debt->reviewed_at) : null;
            if (! $reviewedAt) {
                if (in_array($debt->order_status, ['awaiting_confirmation', 'awaiting_contract', 'rejected', 'cancelled'], true)) {
                    return null;
                }
                $this->fail('as_of_date', 'Đơn '.$debt->order_code.' đang có công nợ nhưng dữ liệu cũ chưa lưu thời điểm duyệt; không thể tạo snapshot đối chiếu đầy đủ.');
            }
            if ($reviewedAt->gt($cutoff) || in_array($debt->order_status, ['awaiting_confirmation', 'awaiting_contract', 'rejected'], true)) {
                return null;
            }
            if ($reviewedAt->gt($effectiveAt)) {
                $effectiveAt = $reviewedAt;
            }
            $cancelledAt = $debt->order_cancelled_at
                ? CarbonImmutable::parse($debt->order_cancelled_at)
                : ($debt->order_status === 'cancelled' ? CarbonImmutable::parse($debt->order_updated_at) : null);
            if ($debt->requires_contract) {
                $signedAt = $debt->signed_at ? CarbonImmutable::parse($debt->signed_at) : null;
                $contractCancelledAt = $debt->contract_cancelled_at ? CarbonImmutable::parse($debt->contract_cancelled_at) : null;
                if (! $signedAt && ! in_array($debt->order_status, ['awaiting_contract', 'awaiting_confirmation', 'rejected', 'cancelled'], true)) {
                    $this->fail('as_of_date', 'Đơn '.$debt->order_code.' yêu cầu hợp đồng nhưng chưa có thời điểm ký; không thể xác định kỳ công nợ chính xác.');
                }
                if (! $signedAt || $signedAt->gt($cutoff) || ($contractCancelledAt && $contractCancelledAt->lte($cutoff))) {
                    return null;
                }
                if ($signedAt->gt($effectiveAt)) {
                    $effectiveAt = $signedAt;
                }
            }
        }

        if ($effectiveAt->gt($cutoff)) {
            return null;
        }
        $returns = $debt->order_id
            ? DB::table('returns')->where('order_id', $debt->order_id)->where('status', 'completed')->where('type', 'return')
                ->where('credit_amount', '>', 0)->orderBy('id')->get(['id', 'code', 'credit_amount', 'completed_at', 'updated_at'])
            : collect();
        $allReturnTotal = $this->sum($returns->map(fn ($return) => ['amount' => $return->credit_amount]));
        $isCancelledOrder = $debt->order_status === 'cancelled';
        $principal = $isCancelledOrder
            ? bcadd((string) ($debt->order_total ?? $debt->amount), $allReturnTotal, 2)
            : bcadd((string) $debt->amount, $allReturnTotal, 2);

        $events = [[
            'type' => 'charge', 'at' => $effectiveAt, 'id' => (int) $debt->id,
            'amount' => $principal, 'reference' => $debt->code, 'description' => 'Phát sinh công nợ',
        ]];
        foreach ($returns as $return) {
            $at = $return->completed_at ? CarbonImmutable::parse($return->completed_at) : CarbonImmutable::parse($return->updated_at);
            $events[] = [
                'type' => 'return', 'at' => $at, 'id' => (int) $return->id,
                'amount' => (string) $return->credit_amount, 'reference' => $return->code,
                'description' => 'Điều chỉnh giảm theo phiếu trả hàng',
            ];
        }
        foreach (DB::table('customer_credits')->whereIn('return_id', $returns->pluck('id'))->orderBy('created_at')->orderBy('id')->get() as $credit) {
            $events[] = [
                'type' => 'credit_issue', 'at' => CarbonImmutable::parse($credit->created_at), 'id' => (int) $credit->id,
                'amount' => (string) $credit->amount, 'reference' => 'CC-'.$credit->id,
                'description' => 'Tiền thừa chuyển thành khoản khách được hưởng',
            ];
        }
        foreach (DB::table('payments')->where('debt_id', $debt->id)->where('paid_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->orderBy('paid_at')->orderBy('id')->get() as $payment) {
            $events[] = [
                'type' => 'payment', 'at' => CarbonImmutable::parse($payment->paid_at), 'id' => (int) $payment->id,
                'amount' => (string) $payment->amount, 'reference' => $payment->code,
                'description' => 'Thu tiền ('.$payment->method.')',
            ];
        }
        foreach (DB::table('customer_credit_movements')->where('debt_id', $debt->id)->where('type', 'offset')
            ->where('created_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->orderBy('created_at')->orderBy('id')->get() as $offset) {
            $events[] = [
                'type' => 'offset', 'at' => CarbonImmutable::parse($offset->created_at), 'id' => (int) $offset->id,
                'amount' => (string) $offset->amount, 'reference' => 'CC-'.$offset->id,
                'description' => 'Cấn trừ khoản khách được hưởng',
            ];
        }
        if ($cancelledAt && $cancelledAt->lte($cutoff)) {
            $events[] = [
                'type' => 'cancellation', 'at' => $cancelledAt, 'id' => (int) $debt->id,
                'amount' => '0.00', 'reference' => $debt->order_code ?: $debt->code,
                'description' => 'Hủy nghĩa vụ theo đơn hàng',
            ];
        }
        usort($events, function (array $a, array $b): int {
            $timeOrder = [$a['at']->getTimestamp(), (int) $a['at']->format('u')]
                <=> [$b['at']->getTimestamp(), (int) $b['at']->format('u')];
            if ($timeOrder !== 0) {
                return $timeOrder;
            }

            $priority = ['charge' => 0, 'payment' => 1, 'return' => 2, 'offset' => 3, 'credit_issue' => 4, 'cancellation' => 5];

            return [$priority[$a['type']], $a['id']] <=> [$priority[$b['type']], $b['id']];
        });

        return ['effective_at' => $effectiveAt, 'principal' => $principal, 'events' => $events];
    }

    private function balanceThrough(string $principal, array $events, CarbonImmutable $through, bool $includeBoundary): string
    {
        $balance = '0.00';
        foreach ($events as $event) {
            if ($event['at']->gt($through) || (! $includeBoundary && $event['at']->eq($through))) {
                continue;
            }
            if ($event['type'] === 'credit_issue') {
                continue;
            }
            if ($event['type'] === 'charge') {
                $balance = bcadd($balance, $principal, 2);
            } elseif ($event['type'] === 'cancellation') {
                $balance = '0.00';
            } else {
                $applied = bccomp($balance, $event['amount'], 2) < 0 ? $balance : $event['amount'];
                $balance = bcsub($balance, $applied, 2);
            }
        }

        return $balance;
    }

    private function periodTotals(string $principal, array $events, CarbonImmutable $start, CarbonImmutable $cutoff): array
    {
        $balance = '0.00';
        $totals = [
            'period_charges' => '0.00', 'period_returns' => '0.00', 'period_collected' => '0.00',
            'period_credit_issued' => '0.00',
            'period_offsets' => '0.00', 'period_cancellations' => '0.00',
        ];
        foreach ($events as $event) {
            if ($event['at']->gt($cutoff)) {
                continue;
            }
            $inPeriod = $event['at']->gte($start);
            if ($event['type'] === 'charge') {
                $balance = bcadd($balance, $principal, 2);
                if ($inPeriod) {
                    $totals['period_charges'] = bcadd($totals['period_charges'], $principal, 2);
                }

                continue;
            }
            if ($event['type'] === 'cancellation') {
                if ($inPeriod) {
                    $totals['period_cancellations'] = bcadd($totals['period_cancellations'], $balance, 2);
                }
                $balance = '0.00';

                continue;
            }
            if ($event['type'] === 'credit_issue') {
                if ($inPeriod) {
                    $totals['period_credit_issued'] = bcadd($totals['period_credit_issued'], $event['amount'], 2);
                }

                continue;
            }
            $applied = bccomp($balance, $event['amount'], 2) < 0 ? $balance : $event['amount'];
            $balance = bcsub($balance, $applied, 2);
            if ($inPeriod) {
                $key = match ($event['type']) {
                    'return' => 'period_returns', 'payment' => 'period_collected', 'offset' => 'period_offsets',
                };
                $totals[$key] = bcadd($totals[$key], $event['amount'], 2);
            }
        }

        return $totals;
    }

    private function visibleEvents(array $events, CarbonImmutable $start, CarbonImmutable $cutoff): array
    {
        $visible = [];
        foreach ($events as $event) {
            if ($event['at']->lt($start) || $event['at']->gt($cutoff)) {
                continue;
            }
            $visible[] = [
                'type' => $event['type'], 'date' => $event['at']->format('Y-m-d H:i:s'),
                'reference' => $event['reference'], 'description' => $event['description'], 'amount' => $event['amount'],
            ];
        }

        return $visible;
    }

    private function assertNoUntrackedOrderEdits($debts, CarbonImmutable $periodStart, CarbonImmutable $cutoff): void
    {
        $orderIds = $debts->pluck('order_id')->filter()->unique()->values();
        if ($orderIds->isEmpty()) {
            return;
        }
        $edit = DB::table('order_items')->whereIn('order_id', $orderIds)
            ->whereColumn('updated_at', '>', 'created_at')
            ->where(function ($query) use ($periodStart, $cutoff) {
                $query->where('updated_at', '>', $cutoff->format('Y-m-d H:i:s.u'))
                    ->orWhereBetween('updated_at', [$periodStart->format('Y-m-d H:i:s.u'), $cutoff->format('Y-m-d H:i:s.u')]);
            })->orderBy('updated_at')->first(['id', 'updated_at']);
        if ($edit) {
            $this->fail('period_start', 'Không thể lập snapshot chính xác vì chi tiết đơn hàng đã thay đổi ngày '.$edit->updated_at
                .'; hãy chọn ngày bắt đầu kỳ sau thay đổi này hoặc ngày chốt mới hơn.');
        }
    }

    private function assertAccountingActor(int $actorId): void
    {
        $role = DB::table('users')->where('id', $actorId)->value('role');
        if (! in_array($role, ['accounting', 'admin'], true)) {
            $this->fail('workflow', 'Chỉ kế toán hoặc quản trị viên được xử lý đối chiếu công nợ.');
        }
    }

    private function parseDate(string $value, string $field, string $message): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->fail($field, $message);
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $this->fail($field, $message);
        }
        if (! $date || $date->toDateString() !== $value) {
            $this->fail($field, $message);
        }

        return $date;
    }

    private function sum($rows): string
    {
        return $rows->reduce(fn (string $total, $row) => bcadd($total, (string) $row['amount'], 2), '0.00');
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
