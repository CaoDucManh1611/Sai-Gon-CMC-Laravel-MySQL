<h3 class="h5 mt-4">Hàng giao thiếu hoặc hỏng</h3>
@if(in_array($role, ['warehouse', 'admin']))
    @foreach($purchases as $purchase)
        @if(in_array($purchase->status, ['approved', 'received']))
            <details class="order-card">
                <summary>Ghi nhận sự vụ · {{ $purchase->code }}</summary>
                <form method="post" action="{{ route('staff.purchases.incidents.create', $purchase->id) }}" class="pt-3 d-flex gap-2 flex-wrap align-items-end">
                    @csrf
                    <label>Mặt hàng
                        <select class="form-select" name="purchase_item_id" required>
                            @foreach($purchaseItems[$purchase->id] ?? [] as $item)
                                @if($item->quantity > $item->received_quantity)
                                    <option value="{{ $item->id }}">{{ $item->product_name }} · chưa nhập {{ \App\Support\UiFormat::quantity($item->quantity - $item->received_quantity) }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    <label>Loại sự vụ
                        <select class="form-select" name="type"><option value="shortage">Giao thiếu</option><option value="damaged">Hàng hỏng / sai quy cách</option></select>
                    </label>
                    <label>Số lượng<input class="form-control" type="number" name="quantity" min="0.001" step="0.001" required></label>
                    <label>Biên bản / ghi chú<input class="form-control" name="note" maxlength="2000" required></label>
                    <button class="btn btn-dark">Lưu sự vụ</button>
                </form>
            </details>
        @endif
    @endforeach
@endif
@foreach($supplierIncidents as $incident)
    <article class="order-card">
        <strong>{{ $incident->product_name }}</strong> · {{ $incident->type === 'shortage' ? 'Giao thiếu' : 'Hàng hỏng / sai quy cách' }} · {{ \App\Support\UiFormat::quantity($incident->quantity) }} · {{ $incident->status === 'open' ? 'Đang xử lý' : 'Đã xử lý' }}
        <p class="mb-1">{{ $incident->note }}</p>
        @if($incident->resolution_note)<p class="mb-1">Kết quả: {{ $incident->resolution_note }}</p>@endif
        @if($incident->status === 'open' && in_array($role, ['warehouse', 'admin']))
            <form method="post" action="{{ route('staff.supplier-incidents.resolve', $incident->id) }}" class="d-flex gap-2 flex-wrap">
                @csrf
                <input class="form-control" style="max-width:420px" name="resolution_note" placeholder="Đổi hàng, giao bù hoặc trả nhà cung cấp" maxlength="2000" required>
                <button class="btn btn-outline-dark">Ghi kết quả xử lý</button>
            </form>
        @endif
    </article>
@endforeach
