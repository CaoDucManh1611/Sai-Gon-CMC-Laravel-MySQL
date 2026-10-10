@extends('layouts.staff')
@section('title','Quản trị | Sài Gòn CMC')
@section('content')
@php
    $role = auth()->user()->role;
@endphp
<div class="container-fluid container-wide pb-5"><div class="page-intro"><div class="eyebrow muted">Danh mục & quản trị</div><h1 class="page-title">Quản lý hệ thống</h1><p class="page-subtitle">Thay đổi danh mục và giá cần được Ban quản lý phê duyệt trước khi áp dụng.</p></div>
@if(in_array($role,['sales','warehouse','admin']))
<section class="mb-5 staff-management-section"><h2>Mặt hàng</h2><p class="staff-section-lead">Chọn mặt hàng để sửa hoặc chọn “Thêm mới” để tạo đề xuất.</p><form class="order-card staff-product-form" method="post" action="{{ route('management.products.propose') }}">@csrf<div class="row g-3"><div class="col-md-3"><label class="form-label">Chọn mặt hàng cần sửa<select class="form-select" name="product_id"><option value="">Thêm mới</option>@foreach($products as $product)<option value="{{ $product->id }}" data-code="{{ $product->code }}" data-name="{{ $product->name }}" data-category-id="{{ $product->category_id }}" data-unit="{{ $product->unit }}" data-specification="{{ $product->specification }}" data-price="{{ $product->price }}" data-minimum-stock="{{ $product->minimum_stock }}" data-active="{{ (int) $product->active }}" data-description="{{ $product->description }}">{{ $product->code }} · {{ $product->name }}</option>@endforeach</select></label></div><div class="col-md-2"><label class="form-label">Mã hàng<input class="form-control" name="code" required></label></div><div class="col-md-3"><label class="form-label">Tên hàng<input class="form-control" name="name" required></label></div><div class="col-md-2"><label class="form-label">Nhóm<select class="form-select" name="category_id">@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label></div><div class="col-md-2"><label class="form-label">Đơn vị<input class="form-control" name="unit" required></label></div><div class="col-md-3"><label class="form-label">Quy cách<input class="form-control" name="specification"></label></div><div class="col-md-2"><label class="form-label">Giá bán<input class="form-control" type="number" name="price" min="0" step="0.01" required></label></div><div class="col-md-2"><label class="form-label">Tồn tối thiểu<input class="form-control" type="number" name="minimum_stock" min="0" step="0.001" value="0"></label></div><div class="col-md-2"><label class="form-label">Trạng thái<select class="form-select" name="active"><option value="1">Đang bán</option><option value="0">Ngừng bán</option></select></label></div><div class="col-md-3"><label class="form-label">Mô tả<input class="form-control" name="description"></label></div></div><button class="btn btn-dark mt-3">Gửi đề xuất</button></form></section>
<section class="mb-5 staff-management-section"><h2>Danh mục</h2><p class="staff-section-lead">Quản lý nhóm vật liệu dùng trong cửa hàng.</p><form class="order-card d-flex gap-2 flex-wrap align-items-end" method="post" action="{{ route('management.categories.propose') }}">@csrf<label class="form-label">Sửa danh mục<select class="form-select" name="id"><option value="">Thêm mới</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label><label class="form-label">Mã<input class="form-control" name="code" required></label><label class="form-label">Tên<input class="form-control" name="name" required></label><label class="form-label">Mô tả<input class="form-control" name="description"></label><button class="btn btn-dark">Gửi đề xuất</button></form></section>@endif
@if(in_array($role,['manager','admin']))
<section id="management-approvals" class="mb-5 staff-management-section staff-record-list" tabindex="0" role="region" aria-label="Phê duyệt thay đổi">
    <h2>Phê duyệt thay đổi</h2>
    @forelse($changes as $change)
        @php
            $details = json_decode($change->proposed_data, true) ?: [];
            $changeTitle = ['product_create' => 'Thêm mặt hàng', 'product_update' => 'Cập nhật mặt hàng', 'category_create' => 'Thêm danh mục', 'category_update' => 'Cập nhật danh mục'][$change->type] ?? 'Thay đổi danh mục';
            $changeStatus = ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Đã từ chối'][$change->status] ?? 'Đang xử lý';
            $categoryName = $categories->firstWhere('id', $details['category_id'] ?? null)->name ?? 'Chưa chọn';
        @endphp
        <article class="order-card d-flex justify-content-between gap-3 flex-wrap">
            <div>
                <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                    <strong>{{ $changeTitle }}</strong>
                    <span class="small muted">Đề xuất #{{ $change->id }}</span>
                    <span class="badge {{ $change->status === 'approved' ? 'bg-success' : ($change->status === 'rejected' ? 'bg-danger' : 'bg-warning text-dark') }}">{{ $changeStatus }}</span>
                </div>
                <dl class="row g-2 mb-0 small">
                    @if(!empty($details['name']))<dt class="col-sm-4">Tên</dt><dd class="col-sm-8 mb-0">{{ $details['name'] }}</dd>@endif
                    @if(!empty($details['code']))<dt class="col-sm-4">Mã quản lý</dt><dd class="col-sm-8 mb-0">{{ $details['code'] }}</dd>@endif
                    @if(str_starts_with($change->type, 'product_'))
                        <dt class="col-sm-4">Nhóm vật liệu</dt><dd class="col-sm-8 mb-0">{{ $categoryName }}</dd>
                        @if(!empty($details['specification']))<dt class="col-sm-4">Quy cách</dt><dd class="col-sm-8 mb-0">{{ $details['specification'] }}</dd>@endif
                        @if(!empty($details['unit']))<dt class="col-sm-4">Đơn vị tính</dt><dd class="col-sm-8 mb-0">{{ $details['unit'] }}</dd>@endif
                        @if(isset($details['price']))<dt class="col-sm-4">Giá bán</dt><dd class="col-sm-8 mb-0">{{ number_format((float) $details['price'], 0, ',', '.') }} ₫</dd>@endif
                        @if(isset($details['minimum_stock']))<dt class="col-sm-4">Tồn tối thiểu</dt><dd class="col-sm-8 mb-0">{{ \App\Support\UiFormat::quantity($details['minimum_stock']) }}</dd>@endif
                        @if(isset($details['active']))<dt class="col-sm-4">Trạng thái bán</dt><dd class="col-sm-8 mb-0">{{ $details['active'] ? 'Đang bán' : 'Ngừng bán' }}</dd>@endif
                    @endif
                    @if(!empty($details['description']))<dt class="col-sm-4">Mô tả</dt><dd class="col-sm-8 mb-0">{{ $details['description'] }}</dd>@endif
                </dl>
            </div>
            @if($change->status === 'pending')
                <form method="post" action="{{ route('management.changes.review', $change->id) }}">
                    @csrf
                    <label class="form-label">Ghi chú<input class="form-control" name="note"></label>
                    <button class="btn btn-dark mt-2" name="decision" value="approve">Duyệt</button>
                    <button class="btn btn-outline-danger mt-2" name="decision" value="reject">Từ chối</button>
                </form>
            @endif
        </article>
    @empty
        <p>Chưa có đề xuất.</p>
    @endforelse
</section>
@endif
@if(in_array($role,['warehouse','admin']))<section class="mb-5 staff-management-section"><h2>Nhà cung cấp & kho</h2><div class="row g-4"><div class="col-lg-6"><form class="order-card" method="post" action="{{ route('management.suppliers.save') }}">@csrf<h3 class="h5">Lưu nhà cung cấp</h3><label class="form-label">Sửa nhà cung cấp<select class="form-select" name="id"><option value="">Thêm mới</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label><label class="form-label">Mã<input class="form-control" name="code" required></label><label class="form-label">Tên<input class="form-control" name="name" required></label><label class="form-label">Số điện thoại<input class="form-control" name="phone"></label><label class="form-label">Email<input class="form-control" name="email" type="email"></label><label class="form-label">Địa chỉ<input class="form-control" name="address"></label><button class="btn btn-dark mt-2">Lưu</button></form></div><div class="col-lg-6"><form class="order-card" method="post" action="{{ route('management.warehouses.save') }}">@csrf<h3 class="h5">Lưu kho</h3><label class="form-label">Sửa kho<select class="form-select" name="id"><option value="">Thêm mới</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></label><label class="form-label">Mã<input class="form-control" name="code" required></label><label class="form-label">Tên<input class="form-control" name="name" required></label><label class="form-label">Địa chỉ<input class="form-control" name="address"></label><button class="btn btn-dark mt-2">Lưu</button></form></div></div></section>@endif
@if(in_array($role,['manager','admin']))<section id="management-customers" class="mb-5 staff-management-section staff-record-list" tabindex="0" role="region" aria-label="Hạn mức công nợ khách hàng"><h2>Hạn mức công nợ khách hàng</h2>@foreach($customers as $customer)<form class="order-card d-flex gap-2 align-items-center flex-wrap" method="post" action="{{ route('management.customers.credit-limit',$customer->id) }}">@csrf<strong>{{ $customer->name }} · {{ $customer->code }}</strong><label class="form-label mb-0">Hạn mức<input class="form-control" name="credit_limit" type="number" min="0" step="0.01" value="{{ $customer->credit_limit }}" required></label><button class="btn btn-dark">Lưu</button></form>@endforeach</section>@endif
@if($role==='admin')<section id="management-users" class="mb-5 staff-management-section staff-record-list" tabindex="0" role="region" aria-label="Tài khoản & phân quyền"><h2>Tài khoản & phân quyền</h2><details class="order-card"><summary>Tạo tài khoản nhân viên/khách hàng</summary><form method="post" action="{{ route('management.users.create') }}" class="row g-2 pt-3">@csrf<div class="col-md-3"><label class="form-label">Họ tên<input class="form-control" name="name" required></label></div><div class="col-md-2"><label class="form-label">Tên đăng nhập<input class="form-control" name="username" required></label></div><div class="col-md-2"><label class="form-label">Số điện thoại<input class="form-control" name="phone"></label></div><div class="col-md-2"><label class="form-label">Email<input class="form-control" name="email" type="email"></label></div><div class="col-md-2"><label class="form-label">Vai trò<select class="form-select" name="role">@foreach(['sales','warehouse','accounting','manager','delivery','customer','admin'] as $item)<option value="{{ $item }}">{{ ['customer'=>'Khách hàng','sales'=>'Kinh doanh','warehouse'=>'Kho','accounting'=>'Kế toán','manager'=>'Quản lý','delivery'=>'Giao nhận','admin'=>'Quản trị viên'][$item] ?? 'Nhân viên' }}</option>@endforeach</select></label></div><div class="col-md-3"><label class="form-label">Mật khẩu tạm<input class="form-control" name="password" type="password" minlength="10" required></label></div><div class="col-12"><button class="btn btn-dark">Tạo tài khoản</button></div></form></details>@foreach($users as $user)<details class="order-card"><summary>{{ $user->name }} · {{ $user->username }} · {{ ['customer'=>'Khách hàng','sales'=>'Kinh doanh','warehouse'=>'Kho','accounting'=>'Kế toán','manager'=>'Quản lý','delivery'=>'Giao nhận','admin'=>'Quản trị viên'][$user->role] ?? 'Nhân viên' }} · {{ $user->status==='active' ? 'Hoạt động' : 'Vô hiệu hóa' }}</summary><div class="d-flex flex-wrap gap-4 pt-3"><form method="post" action="{{ route('management.users.update',$user->id) }}">@csrf @method('put')<label class="form-label">Vai trò<select class="form-select" name="role">@foreach(['customer','sales','warehouse','accounting','manager','delivery','admin'] as $item)<option value="{{ $item }}" @selected($user->role===$item)>{{ ['customer'=>'Khách hàng','sales'=>'Kinh doanh','warehouse'=>'Kho','accounting'=>'Kế toán','manager'=>'Quản lý','delivery'=>'Giao nhận','admin'=>'Quản trị viên'][$item] ?? 'Nhân viên' }}</option>@endforeach</select></label><label class="form-label">Trạng thái<select class="form-select" name="status"><option value="active" @selected($user->status==='active')>Hoạt động</option><option value="disabled" @selected($user->status==='disabled')>Vô hiệu hóa</option></select></label><button class="btn btn-dark ms-2">Cập nhật</button></form><form method="post" action="{{ route('management.users.reset',$user->id) }}">@csrf<label class="form-label">Mật khẩu mới<input class="form-control" name="password" type="password" minlength="10" required></label><label class="form-label">Xác nhận<input class="form-control" name="password_confirmation" type="password" required></label><button class="btn btn-outline-dark ms-2">Đặt lại mật khẩu</button></form></div></details>@endforeach</section>@endif
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[action="{{ route('management.products.propose') }}"]');
    if (!form) return;
    const picker = form.elements.product_id;
    picker.addEventListener('change', () => {
        const values = picker.selectedOptions[0]?.dataset || {};
        for (const name of ['code', 'name', 'category_id', 'unit', 'specification', 'price', 'minimum_stock', 'active', 'description']) {
            const field = form.elements[name];
            if (!field) continue;
            field.value = values[name === 'category_id' ? 'categoryId' : name === 'minimum_stock' ? 'minimumStock' : name] ?? (name === 'minimum_stock' ? '0' : name === 'active' ? '1' : name === 'category_id' ? field.options[0]?.value ?? '' : '');
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
});
</script>
@endsection
