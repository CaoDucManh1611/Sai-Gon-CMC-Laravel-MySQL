@if($return->condition === 'damaged')
    @if(isset($supplierReturns[$return->id]))
        <p class="small mt-2 mb-0">Đã trả nhà cung cấp: {{ $supplierReturns[$return->id]->code }}</p>
        @if($supplierReturns[$return->id]->responsibility_confirmed_at)
            <p class="small mb-0">Đã xác nhận trách nhiệm bởi {{ $supplierReturns[$return->id]->responsibility_confirmer_name ?? 'nhân viên #'.$supplierReturns[$return->id]->responsibility_confirmed_by }} · {{ \Carbon\Carbon::parse($supplierReturns[$return->id]->responsibility_confirmed_at)->format('d/m/Y H:i') }}<br>Căn cứ: {{ $supplierReturns[$return->id]->responsibility_evidence }}</p>
        @else
            <p class="small text-warning-emphasis mb-0">Phiếu cũ chưa lưu căn cứ xác minh trách nhiệm nhà cung cấp.</p>
        @endif
    @elseif(in_array($return->status, ['inspected', 'completed']) && auth()->user()->hasPermission('returns.manage') && ($eligibleReturnSuppliers[$return->product_id] ?? collect())->isNotEmpty())
        <form method="post" action="{{ route('staff.returns.supplier-return', $return->id) }}" class="supplier-return-form mt-2">
            @csrf
            <label class="supplier-return-field">Nhà cung cấp
                <select class="form-select" name="supplier_id" required>
                    @foreach($eligibleReturnSuppliers[$return->product_id] as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach
                </select>
            </label>
            <label class="supplier-return-field">Biên bản trả hàng hỏng<input class="form-control" name="note" maxlength="2000" required></label>
            <label class="supplier-return-field">Căn cứ xác minh trách nhiệm<input class="form-control" name="responsibility_evidence" maxlength="2000" placeholder="Ví dụ: đối chiếu phiếu nhập và biên bản kiểm tra" required></label>
            <label class="d-flex align-items-center gap-2"><input type="checkbox" name="responsibility_confirmed" value="1" required>Đã xác minh nhà cung cấp này chịu trách nhiệm về hàng lỗi</label>
            <button class="btn btn-outline-dark">Ghi nhận trả nhà cung cấp</button>
        </form>
    @elseif(in_array($return->status, ['inspected', 'completed']) && auth()->user()->hasPermission('returns.manage'))
        <p class="small muted mt-2 mb-0">Chưa có phiếu nhập từ nhà cung cấp cho vật liệu này; cần đối chiếu nguồn hàng trước khi trả.</p>
    @endif
@endif
