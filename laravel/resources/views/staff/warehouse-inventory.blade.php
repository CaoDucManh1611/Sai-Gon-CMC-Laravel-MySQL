<section id="inventory" class="warehouse-inventory mb-5">
    <nav class="warehouse-tabs" aria-label="Chế độ xem tồn kho">
        <a href="{{ route('staff.section', 'inventory') }}" @class(['is-active' => !in_array(request('view'), ['movements', 'stocktakes'], true)]) @if(!in_array(request('view'), ['movements', 'stocktakes'], true)) aria-current="page" @endif>Tồn kho thực tế</a>
        <a href="{{ route('staff.section', ['section' => 'inventory', 'view' => 'movements']) }}" @class(['is-active' => request('view') === 'movements']) @if(request('view') === 'movements') aria-current="page" @endif>Lịch sử nhập, xuất</a>
        <a href="{{ route('staff.section', ['section' => 'inventory', 'view' => 'stocktakes']) }}" @class(['is-active' => request('view') === 'stocktakes']) @if(request('view') === 'stocktakes') aria-current="page" @endif>Kiểm kê</a>
    </nav>
    @if(request('view') === 'movements')
        <section class="warehouse-stock-panel" aria-labelledby="stock-movements-heading">
            <h2 id="stock-movements-heading">Lịch sử nhập, xuất và điều chỉnh</h2>
            <div class="table-responsive"><table class="table"><thead><tr><th>Thời điểm</th><th>Chứng từ</th><th>Kho</th><th>Vật liệu</th><th>Thay đổi</th><th>Tồn sau</th></tr></thead><tbody>
                @forelse($stockMovements as $movement)
                    <tr><td>{{ $movement->created_at }}</td><td>{{ $movement->document_code }} · {{ ['opening'=>'Tồn đầu kỳ','receipt'=>'Nhập kho','issue'=>'Xuất kho','return'=>'Nhập hàng trả','return_receipt'=>'Nhập hàng trả','exchange_issue'=>'Xuất hàng thay thế','adjustment'=>'Điều chỉnh kiểm kê'][$movement->document_type] ?? 'Giao dịch kho' }}</td><td>{{ $movement->warehouse_name }}</td><td>{{ $movement->product_name }}</td><td>{{ \App\Support\UiFormat::quantity($movement->change) }}</td><td>{{ \App\Support\UiFormat::quantity($movement->balance_after) }}</td></tr>
                @empty<tr><td colspan="6" class="text-center">Chưa có giao dịch kho.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @elseif(request('view') !== 'stocktakes')
        <section class="warehouse-stock-panel" aria-labelledby="current-stock-heading">
            <h2 id="current-stock-heading">Tồn kho thực tế</h2>
            <div class="table-responsive"><table class="table"><thead><tr><th>Kho</th><th>Vật liệu</th><th>Số lượng</th><th>Tồn tối thiểu</th></tr></thead><tbody>
                @forelse($inventory as $stock)
                    <tr class="{{ $stock->quantity < $stock->minimum_stock ? 'table-warning' : '' }}"><td>{{ $stock->warehouse_name }}</td><td>{{ $stock->product_name }}</td><td>{{ \App\Support\UiFormat::quantity($stock->quantity) }}</td><td>{{ \App\Support\UiFormat::quantity($stock->minimum_stock) }}</td></tr>
                @empty<tr><td colspan="4" class="text-center">Chưa có số liệu tồn kho.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @else
        @if(in_array($role, ['warehouse', 'admin']))
            <section class="warehouse-stock-panel" aria-labelledby="stocktake-heading"><h2 id="stocktake-heading">Lập phiếu kiểm kê</h2>
                <form method="post" action="{{ route('staff.stocktakes.create') }}" class="warehouse-stocktake-form">@csrf
                    <label class="form-label">Kho<select class="form-select" name="warehouse_id">@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></label>
                    <label class="form-label">Vật liệu<select class="form-select" name="product_id">@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></label>
                    <label class="form-label">Đếm thực tế<input class="form-control" name="quantity" type="number" min="0" step="0.001" required></label>
                    <label class="form-label">Lý do chênh lệch<input class="form-control" name="reason" required></label>
                    <button class="btn btn-dark" type="submit">Trình kiểm kê</button>
                </form>
            </section>
        @endif
        @if($stocktakes->isNotEmpty())
            <section class="warehouse-stock-panel" aria-labelledby="stocktake-list-heading"><h2 id="stocktake-list-heading">Phiếu kiểm kê gần đây</h2>
                @foreach($stocktakes as $stocktake)
                    <div class="warehouse-stocktake-row"><div><strong>{{ $stocktake->code }}</strong> · {{ \App\Support\UiFormat::status($stocktake->status) }} · {{ $stocktake->note }}<small>{{ $stocktakeItems[$stocktake->id]->product_name ?? '' }}: sổ sách {{ \App\Support\UiFormat::quantity($stocktakeItems[$stocktake->id]->book_quantity ?? 0) }}, đếm thực tế {{ \App\Support\UiFormat::quantity($stocktakeItems[$stocktake->id]->quantity ?? 0) }}</small></div>
                        @if($stocktake->status === 'pending' && in_array($role, ['manager', 'admin']))<form method="post" action="{{ route('staff.stocktakes.review', $stocktake->id) }}">@csrf<button class="btn btn-dark" name="decision" value="approve">Duyệt điều chỉnh</button><button class="btn btn-outline-danger" name="decision" value="reject">Từ chối</button></form>@endif
                    </div>
                @endforeach
            </section>
        @endif
    @endif
</section>
