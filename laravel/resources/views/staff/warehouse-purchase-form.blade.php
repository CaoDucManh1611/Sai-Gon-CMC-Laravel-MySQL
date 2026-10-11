<section class="warehouse-purchase-form" aria-labelledby="new-purchase-heading">
    <h3 id="new-purchase-heading">Lập đề xuất nhập hàng</h3>
    <form method="post" action="{{ route('staff.purchases.create') }}">
        @csrf
        <div class="warehouse-purchase-fields">
            <label class="form-label">Nhà cung cấp<select class="form-select" name="supplier_id" required>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label>
            <label class="form-label">Kho nhận hàng<select class="form-select" name="warehouse_id" required>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></label>
            <label class="form-label">Vật liệu<select class="form-select" name="items[0][product_id]" required>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></label>
            <label class="form-label">Số lượng<input class="form-control" type="number" name="items[0][quantity]" min="0.001" step="0.001" required></label>
            <label class="form-label">Giá nhập<input class="form-control" type="number" name="items[0][unit_price]" min="0" step="0.01" required></label>
            <button class="btn btn-dark" type="submit">Gửi đề xuất nhập hàng</button>
        </div>
    </form>
</section>
