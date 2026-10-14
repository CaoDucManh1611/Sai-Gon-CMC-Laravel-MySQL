@if($return->condition === 'damaged')
    @if(isset($supplierReturns[$return->id]))
        <p class="small mt-2 mb-0">Đã trả nhà cung cấp: {{ $supplierReturns[$return->id]->code }}</p>
    @elseif(in_array($return->status, ['inspected', 'completed']) && in_array($role, ['warehouse', 'admin']) && ($eligibleReturnSuppliers[$return->product_id] ?? collect())->isNotEmpty())
        <form method="post" action="{{ route('staff.returns.supplier-return', $return->id) }}" class="supplier-return-form mt-2">
            @csrf
            <label class="supplier-return-field">Nhà cung cấp
                <select class="form-select" name="supplier_id" required>
                    @foreach($eligibleReturnSuppliers[$return->product_id] as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach
                </select>
            </label>
            <label class="supplier-return-field">Biên bản trả hàng hỏng<input class="form-control" name="note" maxlength="2000" required></label>
            <button class="btn btn-outline-dark">Ghi nhận trả nhà cung cấp</button>
        </form>
    @elseif(in_array($return->status, ['inspected', 'completed']) && in_array($role, ['warehouse', 'admin']))
        <p class="small muted mt-2 mb-0">Chưa có phiếu nhập từ nhà cung cấp cho vật liệu này; cần đối chiếu nguồn hàng trước khi trả.</p>
    @endif
@endif
