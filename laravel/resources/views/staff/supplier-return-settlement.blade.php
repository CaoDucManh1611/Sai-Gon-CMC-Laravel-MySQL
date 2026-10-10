<section class="mb-5" aria-labelledby="supplier-return-heading">
    <h2 class="h4" id="supplier-return-heading">Bù trừ hàng hỏng trả nhà cung cấp</h2>
    @foreach($supplierReturns as $supplierReturn)
        <article class="order-card">
            <strong>{{ $supplierReturn->code }}</strong> · nhà cung cấp #{{ $supplierReturn->supplier_id }} · {{ \App\Support\UiFormat::quantity($supplierReturn->quantity) }} đơn vị
            <p class="mb-1">{{ $supplierReturn->note }}</p>
            @if($supplierReturn->settled_at)
                <p class="mb-0">Đã bù trừ {{ number_format($supplierReturn->credit_amount, 0, ',', '.') }} ₫ · {{ $supplierReturn->settlement_note }}</p>
            @elseif(in_array($role, ['accounting', 'admin']))
                <form method="post" action="{{ route('staff.supplier-returns.settle', $supplierReturn->id) }}" class="d-flex flex-wrap gap-2 align-items-end">
                    @csrf
                    <label>Công nợ cùng nhà cung cấp
                        <select class="form-select" name="debt_id" required>
                            @foreach($supplierPayables->where('supplier_id', $supplierReturn->supplier_id) as $debt)
                                <option value="{{ $debt->id }}">{{ $debt->code }} · còn {{ number_format($debt->amount - $debt->paid, 0, ',', '.') }} ₫</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Giá trị bù trừ đã xác nhận<input class="form-control" type="number" name="credit_amount" min="0.01" step="0.01" required></label>
                    <label>Chứng từ / ghi chú<input class="form-control" name="settlement_note" maxlength="2000" required></label>
                    <button class="btn btn-outline-dark">Ghi bù trừ</button>
                </form>
            @endif
        </article>
    @endforeach
</section>
