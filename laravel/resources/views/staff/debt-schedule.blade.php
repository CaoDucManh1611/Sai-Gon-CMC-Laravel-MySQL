<section class="mb-5" aria-labelledby="debt-schedule-heading">
    <h2 class="h4" id="debt-schedule-heading">Lịch quyết toán công nợ</h2>
    @foreach($debts as $debt)
        @if($debt->status === 'open' && $debt->paid < $debt->amount && ($debt->direction === 'payable' || (!in_array($debt->order_status, ['awaiting_confirmation', 'awaiting_contract', 'rejected', 'cancelled'], true) && (!$debt->requires_contract || $debt->contract_status === 'signed'))))
            <details class="order-card">
                <summary>{{ $debt->code }} · {{ $debt->direction === 'receivable' ? 'Phải thu' : 'Phải trả' }} · còn {{ number_format($debt->amount - $debt->paid, 0, ',', '.') }} ₫ @if($debt->due_on) · hẹn {{ $debt->due_on }} @endif</summary>
                @if($debt->settlement_note)<p class="pt-2 mb-1">{{ $debt->settlement_note }}</p>@endif
                @if(auth()->user()->hasPermission('debts.manage'))
                    <form method="post" action="{{ route('staff.debts.schedule', $debt->id) }}" class="pt-2 d-flex gap-2 flex-wrap align-items-end">
                        @csrf
                        <label>Ngày quyết toán<input class="form-control" type="date" name="due_on" min="{{ now()->toDateString() }}" value="{{ $debt->due_on }}" required></label>
                        <label>Ghi chú đối chiếu<input class="form-control" name="settlement_note" value="{{ $debt->settlement_note }}" maxlength="2000" required></label>
                        <button class="btn finance-save-schedule">Lưu lịch</button>
                    </form>
                @endif
            </details>
        @endif
    @endforeach
</section>

