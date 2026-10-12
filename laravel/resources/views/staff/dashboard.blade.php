@extends('layouts.staff')
@section('title', in_array(auth()->user()->role, ['manager', 'admin'], true) && ($activeSection ?? 'overview') === 'overview' ? 'Tổng quan | Sài Gòn CMC' : 'Bảng làm việc | Sài Gòn CMC')
@section('content')
@php
    $role = auth()->user()->role;
    $staffSections = [
        'quotes' => ['Báo giá', 'BG', ['sales', 'manager', 'admin']],
        'contracts' => ['Hợp đồng', 'HĐ', ['sales', 'manager', 'admin']],
        'sales_orders' => ['Đơn hàng', 'DH', ['sales', 'manager', 'admin']],
        'finance' => ['Tài chính & công nợ', 'TC', ['accounting', 'manager', 'admin']],
        'purchases' => ['Nhập hàng', 'NH', ['warehouse', 'manager', 'admin']],
        'orders' => ['Xuất kho & giao nhận', 'GN', ['warehouse', 'delivery', 'accounting', 'manager', 'admin']],
        'returns' => ['Đổi trả', 'ĐT', ['warehouse', 'accounting', 'manager', 'admin']],
        'inventory' => ['Tồn kho', 'TK', ['warehouse', 'manager', 'admin']],
    ];
@endphp
<div class="container-fluid container-wide pb-5">
  <div class="page-intro"><h1 class="page-title">{{ $activeSection === 'overview' ? (in_array($role, ['manager', 'admin'], true) ? 'Tổng quan' : 'Bảng làm việc') : ($staffSections[$activeSection][0] ?? 'Bảng làm việc') }}</h1></div>
  @if($activeSection === 'overview')
  @if($role === 'sales')
    @include('staff.sales-overview')
  @elseif($role === 'warehouse')
    @include('staff.warehouse-overview')
  @elseif($role === 'accounting')
    @include('staff.accounting-overview')
  @elseif($role === 'delivery')
    @include('staff.delivery-overview')
  @elseif($role === 'manager')
    @include('staff.manager-overview')
  @elseif($role === 'admin')
    @include('staff.admin-overview')
  @else
  @php($staffCounts = ['quotes'=>$quotes->count(),'contracts'=>$contracts->count(),'finance'=>$debts->count(),'purchases'=>$purchases->count(),'orders'=>$orders->count(),'returns'=>$returns->count(),'inventory'=>$inventory->count()])
  <div class="staff-overview-grid" aria-label="Bản ghi gần đây">
    @foreach($staffSections as $key => [$label, $icon, $roles])
      @if(in_array($role, $roles, true))
      <a class="staff-stat" href="{{ route('staff.section', $key) }}"><span class="staff-stat-label">{{ $label }}</span><strong>{{ $staffCounts[$key] }}</strong><small>Xem hồ sơ <span aria-hidden="true">↗</span></small></a>
      @endif
    @endforeach
  </div>
  @endif
  @else
  <div @class(['staff-panel', 'sales-workspace-panel' => $activeSection === 'quotes', 'warehouse-workspace-panel' => in_array($activeSection, ['purchases', 'orders', 'returns', 'inventory'], true), 'finance-workspace-panel' => $activeSection === 'finance'])><div class="staff-workspace-scroll" tabindex="0" role="region" aria-label="Danh sách và thao tác {{ $staffSections[$activeSection][0] ?? 'nghiệp vụ' }}">
  @if(in_array($role,['sales','manager','admin']) && $activeSection==='quotes')
  @include('staff.sales-quotes')
  @endif
  @if(in_array($role,['sales','manager','admin']) && $activeSection==='sales_orders')
  @include('staff.sales-orders')
  @endif
  @if(in_array($role,['sales','manager','admin']) && $activeSection==='contracts')
  <section id="contracts" class="mb-5"><form method="get" action="{{ route('staff.section','contracts') }}" class="d-flex gap-2 mb-3"><label class="visually-hidden" for="contract-search">Mã hợp đồng</label><input id="contract-search" class="form-control" style="max-width:280px" name="contract" value="{{ request('contract') }}" placeholder="Tìm theo mã hợp đồng"><button class="btn btn-outline-dark">Tìm</button></form>@forelse($contracts as $contract)<details class="order-card"><summary><strong>{{ $contract->code }}</strong> · {{ \App\Support\UiFormat::status($contract->status) }} · {{ number_format($contract->total,0,',','.') }} ₫</summary><div class="pt-3"><p>Cọc yêu cầu: {{ number_format($contract->deposit_required,0,',','.') }} ₫ · {{ $contract->payment_terms }}</p><p>Giao hàng: {{ $contract->delivery_terms }}</p>@if($contract->approval_note)<p>Ý kiến quản lý: {{ $contract->approval_note }}</p>@endif @if($contract->customer_note)<p>Ý kiến khách hàng: {{ $contract->customer_note }}</p>@endif @if($contract->cancellation_note)<p>Lý do hủy: {{ $contract->cancellation_note }}</p>@endif
    @if(in_array($contract->status,['draft','changes_requested']) && in_array($role,['sales','admin']))<form method="post" action="{{ route('staff.contracts.update',$contract->id) }}">@csrf @method('put')<div class="row g-2"><div class="col-md-3"><label class="form-label">Cọc<input class="form-control" type="number" name="deposit_required" step="0.01" min="0" value="{{ $contract->deposit_required }}" required></label></div><div class="col-md-4"><label class="form-label">Thanh toán<textarea class="form-control" name="payment_terms" required>{{ $contract->payment_terms }}</textarea></label></div><div class="col-md-4"><label class="form-label">Giao hàng<textarea class="form-control" name="delivery_terms" required>{{ $contract->delivery_terms }}</textarea></label></div></div><button class="btn btn-outline-dark mt-2">Lưu sửa đổi</button></form><form method="post" action="{{ route('staff.contracts.submit',$contract->id) }}" class="mt-2">@csrf<button class="btn btn-dark">Trình Ban quản lý duyệt</button></form>@endif
    @if($contract->status==='pending_approval' && in_array($role,['manager','admin']))<form method="post" action="{{ route('staff.contracts.review',$contract->id) }}">@csrf<label class="form-label">Ghi chú<input class="form-control" name="note"></label><button class="btn btn-dark ms-2" name="decision" value="approve">Phê duyệt</button><button class="btn btn-outline-danger ms-2" name="decision" value="revise">Yêu cầu sửa</button></form>@endif
    @if(in_array($contract->status,['draft','pending_approval','changes_requested','approved']) && in_array($role,['manager','admin']))<form method="post" action="{{ route('staff.contracts.cancel',$contract->id) }}" class="mt-2">@csrf<label class="form-label">Lý do hủy<input class="form-control" name="reason" maxlength="2000" required></label><button class="btn btn-outline-danger ms-2">Hủy hợp đồng</button></form>@endif
    @if($contract->status==='approved' && $contract->customer_confirmed_at && in_array($role,['sales','admin']))<form method="post" enctype="multipart/form-data" action="{{ route('staff.contracts.sign',$contract->id) }}">@csrf<label class="form-label">Bản hợp đồng đã ký (PDF hoặc ảnh)<input class="form-control" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required></label><button class="btn btn-dark ms-2">Ghi nhận đã ký và tạo đơn</button></form>@endif</div></details>@empty<p class="contract-empty">Chưa có hợp đồng.</p>@endforelse</section>
  @endif
  @if(in_array($role,['accounting','manager','admin']) && $activeSection==='finance')<section id="finance" class="mb-5"><h2>Tài chính & công nợ</h2><div class="table-responsive"><table class="table"><thead><tr><th>Mã</th><th>Loại</th><th>Giá trị</th><th>Đã trả</th><th>Còn lại</th><th>Ghi nhận thanh toán</th></tr></thead><tbody>@foreach($debts as $debt)<tr><td>{{ $debt->code }}</td><td>{{ $debt->direction==='receivable'?'Phải thu':'Phải trả' }}</td><td>{{ number_format($debt->amount,0,',','.') }} ₫</td><td>{{ number_format($debt->paid,0,',','.') }} ₫</td><td>{{ number_format(max(0,$debt->amount-$debt->paid),0,',','.') }} ₫</td><td>@if($debt->paid<$debt->amount && in_array($role,['accounting','admin']) && !in_array($debt->order_status, ['awaiting_confirmation', 'rejected', 'cancelled'], true))<form class="d-flex gap-1 flex-wrap" method="post" action="{{ route('staff.payments.record',$debt->id) }}">@csrf<input class="form-control form-control-sm" style="width:105px" type="number" name="amount" min="0.01" step="0.01" placeholder="Số tiền" required><select class="form-select form-select-sm" style="width:130px" name="method"><option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option></select><input class="form-control form-control-sm" style="width:110px" name="reference" placeholder="Mã giao dịch"><button class="btn btn-dark btn-sm">Ghi nhận</button></form>@endif</td></tr>@endforeach</tbody></table></div></section>@endif
  @if(in_array($role,['accounting','manager','admin']) && $activeSection==='finance')
    @include('staff.debt-schedule')
    @include('staff.customer-credits')
    @include('staff.supplier-return-settlement')
  @endif
  @if(in_array($role,['warehouse','manager','admin']) && $activeSection==='purchases')
  <section id="purchases" class="warehouse-purchases mb-5" aria-label="Nhập hàng">
    @if(in_array($role,['warehouse','admin']))@include('staff.warehouse-purchase-form')@endif
    @foreach($purchases as $purchase)<details class="order-card"><summary><strong>{{ $purchase->code }}</strong> · {{ \App\Support\UiFormat::status($purchase->status) }} · {{ number_format($purchase->total,0,',','.') }} ₫</summary><div class="pt-3">@if($purchase->status==='pending' && in_array($role,['manager','admin']))<form method="post" action="{{ route('staff.purchases.approve',$purchase->id) }}">@csrf<button class="btn btn-dark">Phê duyệt nhập hàng</button></form>@endif @if($purchase->status==='approved' && in_array($role,['warehouse','admin']))<form method="post" action="{{ route('staff.purchases.receive',$purchase->id) }}">@csrf @foreach($purchaseItems[$purchase->id] ?? [] as $item)<label class="form-label me-3">{{ $item->product_name }} · còn {{ \App\Support\UiFormat::quantity($item->quantity-$item->received_quantity) }}<input class="form-control" type="number" name="received[{{ $item->id }}]" min="0" max="{{ $item->quantity-$item->received_quantity }}" step="0.001" value="0" required></label>@endforeach<button class="btn btn-dark">Xác nhận số lượng thực nhận</button></form>@endif</div></details>@endforeach
    @include('staff.supplier-incidents')
  </section>@endif
  @if(in_array($role,['warehouse','delivery','accounting','manager','admin']) && $activeSection==='orders')<section id="orders" class="mb-5" aria-label="Danh sách đơn cần xuất kho và giao nhận">@foreach($orders as $order)<div class="order-card d-flex justify-content-between align-items-center flex-wrap gap-2"><span><strong>{{ $order->code }}</strong> · {{ \App\Support\UiFormat::status($order->status) }}<small class="d-block muted">{{ $order->delivery_address }}</small></span>@if($order->status==='ready_to_issue' && !isset($invoices[$order->id]) && in_array($role,['accounting','admin']))<form method="post" action="{{ route('staff.orders.invoice',$order->id) }}">@csrf<button class="btn btn-outline-dark">Lập hóa đơn</button></form>@endif @if(isset($invoices[$order->id]))<span class="small">Hóa đơn {{ $invoices[$order->id]->code }}</span>@endif @if($order->status==='ready_to_issue' && $order->released_by && in_array($role,['warehouse','admin']))<form method="post" action="{{ route('staff.orders.issue',$order->id) }}">@csrf @if($order->fulfillment_method==='pickup')<input type="hidden" name="warehouse_id" value="{{ $order->pickup_warehouse_id }}"><span class="small">Kho nhận: {{ $warehouses->firstWhere('id',$order->pickup_warehouse_id)->name ?? '' }}</span>@else<select class="form-select d-inline-block" style="width:180px" name="warehouse_id">@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select>@endif<button class="btn btn-dark">Duyệt xuất kho</button></form>@endif @if($order->status==='ready_to_ship' && $order->fulfillment_method==='pickup' && in_array($role,['warehouse','admin']))<form method="post" action="{{ route('staff.orders.pickup',$order->id) }}">@csrf<input class="form-control d-inline-block" style="width:180px" name="recipient" placeholder="Tên người nhận" required><button class="btn btn-dark">Bàn giao tại kho</button></form>@endif @if($order->status==='ready_to_ship' && $order->fulfillment_method!=='pickup' && in_array($role,['warehouse','admin']))<form class="d-flex gap-1 flex-wrap" method="post" action="{{ route('staff.orders.ship',$order->id) }}">@csrf<input class="form-control" style="width:140px" name="carrier_name" placeholder="Đơn vị vận chuyển" required><input class="form-control" style="width:110px" name="vehicle" placeholder="Phương tiện" required><input class="form-control" style="width:160px" name="route_note" placeholder="Lộ trình" required><button class="btn btn-dark">Lập chuyến</button></form>@endif</div>@endforeach
    @foreach($shipments as $shipment)@if(in_array($shipment->status,['planned','shipping']) && in_array($role,['warehouse','delivery','admin']))<details class="order-card"><summary>{{ $shipment->code }} · {{ \App\Support\UiFormat::status($shipment->status) }}</summary>@if($shipment->status==='planned')<form class="pt-3 d-flex gap-2 flex-wrap" method="post" action="{{ route('staff.shipments.pickup',$shipment->id) }}">@csrf<input class="form-control" style="width:220px" name="receiver" placeholder="Người vận chuyển nhận hàng" required><button class="btn btn-dark">Xác nhận nhận hàng</button></form>@else<form class="pt-3 d-flex gap-2 flex-wrap" method="post" action="{{ route('staff.shipments.handover',$shipment->id) }}">@csrf<input class="form-control" style="width:170px" name="recipient" placeholder="Người nhận" required><input class="form-control" style="width:220px" name="condition_note" placeholder="Tình trạng / biên bản" required><select class="form-select" style="width:150px" name="accepted"><option value="1">Đã nhận đủ</option><option value="0">Có sự cố</option></select><button class="btn btn-dark">Xác nhận bàn giao</button></form>@endif</details>@endif @endforeach @foreach($shipments as $shipment)@if($shipment->status==='incident' && in_array($role,['warehouse','delivery','admin']))<details class="order-card"><summary>{{ $shipment->code }} · Giao hàng gặp sự cố</summary><form class="pt-3 d-flex gap-2 flex-wrap" method="post" action="{{ route('staff.shipments.reschedule',$shipment->id) }}">@csrf<input class="form-control" style="width:160px" name="vehicle" placeholder="Phương tiện mới" required><input class="form-control" style="width:230px" name="route_note" placeholder="Lộ trình và thời gian giao lại" required><button class="btn btn-dark">Lên lịch giao lại</button></form></details>@endif @endforeach</section>@endif
  @if($activeSection==='orders')
    @include('staff.delivery-runs')
  @endif
  @if(in_array($role,['warehouse','accounting','manager','admin']) && $activeSection==='returns')<section id="returns" class="mb-5"><h2>Danh sách yêu cầu đổi trả</h2>@forelse($returns as $return)<div class="order-card"><strong>{{ $return->code }}</strong> · {{ \App\Support\UiFormat::status($return->status) }} · {{ $return->reason }} · {{ \App\Support\UiFormat::quantity($return->quantity) }}@if($return->status==='requested' && in_array($role,['warehouse','admin']))<form class="mt-2 d-flex gap-2 flex-wrap" method="post" action="{{ route('staff.returns.inspect',$return->id) }}">@csrf<select class="form-select" style="width:180px" name="warehouse_id">@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select><select class="form-select" style="width:160px" name="reusable"><option value="1">Còn sử dụng được</option><option value="0">Hư hỏng</option></select><input class="form-control" style="width:220px" name="note" placeholder="Kết quả kiểm tra" required><button class="btn btn-dark">Xác nhận kiểm tra</button></form>@endif @if($return->type==='exchange' && $return->status==='inspected' && in_array($role,['warehouse','admin']))<form class="mt-2 d-flex gap-2 flex-wrap" method="post" action="{{ route('staff.returns.exchange',$return->id) }}">@csrf<select class="form-select" name="warehouse_id" style="width:180px">@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select><input class="form-control" name="carrier_name" placeholder="Đơn vị giao" required><input class="form-control" name="vehicle" placeholder="Phương tiện" required><input class="form-control" name="route_note" placeholder="Lộ trình" required><button class="btn btn-dark">Giao hàng thay thế</button></form>@endif @if($return->type==='return' && $return->status==='inspected' && in_array($role,['accounting','admin']))<form method="post" action="{{ route('staff.returns.settle',$return->id) }}" class="mt-2">@csrf<button class="btn btn-dark">Điều chỉnh công nợ {{ number_format($return->credit_amount,0,',','.') }} ₫</button></form>@endif @include('staff.supplier-return-action')</div>@empty<div class="warehouse-empty warehouse-empty-center">Không có yêu cầu đổi trả</div>@endforelse</section>@endif
  @if(in_array($role,['warehouse','manager','admin']) && $activeSection==='inventory')
    @include('staff.warehouse-inventory')
  @endif
  </div></div>@endif
</div>
@endsection















