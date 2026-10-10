<section class="mb-5" aria-labelledby="customer-credits-heading">
    <h2 class="h4" id="customer-credits-heading">Tiền khách được hoàn hoặc cấn trừ</h2>
    @forelse($customerCredits as $credit)
        @php($remaining = bcsub((string) $credit->amount, (string) ($creditUsed[$credit->id] ?? 0), 2))
        <article class="order-card">
            <strong>{{ $credit->customer_name }}</strong> · phiếu trả #{{ $credit->return_id }} · còn {{ number_format($remaining, 0, ',', '.') }} ₫
            @if($credit->status === 'open' && in_array($role, ['accounting', 'admin']))
                <form method="post" action="{{ route('staff.customer-credits.use', $credit->id) }}" class="d-flex gap-2 flex-wrap align-items-end mt-2">
                    @csrf
                    <label>Hình thức
                        <select class="form-select" name="type" required>
                            <option value="refund">Hoàn tiền</option>
                            <option value="offset">Cấn trừ công nợ</option>
                        </select>
                    </label>
                    <label>Số tiền<input class="form-control" type="number" name="amount" min="0.01" max="{{ $remaining }}" step="0.01" required></label>
                    <label>Đơn cần cấn trừ
                        <select class="form-select" name="debt_id">
                            <option value="">Chỉ chọn khi cấn trừ</option>
                            @foreach($customerDebts->where('customer_id', $credit->customer_id) as $debt)
                                <option value="{{ $debt->id }}">{{ $debt->code }} · còn {{ number_format($debt->amount - $debt->paid, 0, ',', '.') }} ₫</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Phương thức hoàn
                        <select class="form-select" name="method">
                            <option value="">Chỉ chọn khi hoàn tiền</option>
                            <option value="cash">Tiền mặt</option>
                            <option value="bank_transfer">Chuyển khoản</option>
                        </select>
                    </label>
                    <label>Mã giao dịch<input class="form-control" name="reference" maxlength="150"></label>
                    <button class="btn btn-outline-dark">Ghi nhận</button>
                </form>
            @endif
        </article>
    @empty
        <p>Chưa có khoản cần hoàn hoặc cấn trừ.</p>
    @endforelse
</section>
