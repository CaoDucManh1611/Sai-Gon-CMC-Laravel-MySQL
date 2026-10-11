@extends('layouts.store')
@section('title',$quote->code.' | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5">
    <div class="page-intro">
        <div class="breadcrumbs"><a href="{{ route('my.quotes') }}">Báo giá</a> / {{ $quote->code }}</div>
        <div class="eyebrow muted">Trạng thái: {{ \App\Support\UiFormat::status($quote->status) }}</div>
        <h1 class="page-title">Báo giá {{ $quote->code }}</h1>
        <p class="page-subtitle">Địa chỉ giao: {{ $quote->delivery_address }}</p>
    </div>
    <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Vật liệu</th><th>Quy cách</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead>
        <tbody>@foreach($items as $item)<tr>
            <td><a class="quote-product" href="{{ route('product',$item->product_id) }}"><span class="material-image {{ $item->image_path }}" role="img" aria-label="Ảnh minh họa {{ $item->name }}"></span><span>{{ $item->name }}</span></a></td>
            <td>{{ $item->specification }}</td><td>{{ \App\Support\UiFormat::quantity($item->quantity) }} {{ $item->unit }}</td><td>{{ number_format($item->unit_price,0,',','.') }} ₫</td><td>{{ number_format($item->line_total,0,',','.') }} ₫</td>
        </tr>@endforeach</tbody>
    </table></div>
    <div class="text-end fs-4">Tổng: <strong>{{ number_format($quote->total,0,',','.') }} ₫</strong></div>
    @if($quote->customer_note)<p class="mt-3">Ghi chú của bạn: {{ $quote->customer_note }}</p>@endif
    @if($quote->staff_note)<p class="mt-3">Ghi chú từ nhân viên: {{ $quote->staff_note }}</p>@endif
    @if($quote->status==='requested')
        <details class="quote-edit mt-4" id="chinh-sua" @if(request('edit')) open @endif><summary>Chỉnh sửa yêu cầu báo giá</summary>
            <form method="post" action="{{ route('my.quote.update',$quote->id) }}" class="mt-3">@csrf @method('put')
                <label class="form-label" for="quoteAddress">Địa chỉ công trình</label><textarea class="form-control" id="quoteAddress" name="delivery_address" required>{{ old('delivery_address', $quote->delivery_address) }}</textarea>
                <label class="form-label mt-3" for="quoteNote">Ghi chú thêm</label><textarea class="form-control" id="quoteNote" name="note">{{ old('note', $quote->customer_note) }}</textarea>
                <div class="mt-3">@foreach($items as $item)<label class="form-label d-flex align-items-center gap-3 mb-2">{{ str_replace('Ø', 'phi ', $item->name) }} ({{ $item->unit }})<input class="form-control quote-quantity" type="number" name="quantities[{{ $item->id }}]" value="{{ \App\Support\UiFormat::inputQuantity(old('quantities.'.$item->id, $item->quantity)) }}" min="{{ in_array($item->unit, ['m³','kg'], true) ? '0.001' : '1' }}" step="{{ in_array($item->unit, ['m³','kg'], true) ? '0.001' : '1' }}" max="1000000" required></label>@endforeach</div>
                <button class="btn-editorial dark mt-3" type="submit">Lưu thay đổi</button>
            </form>
        </details>
        <form method="post" action="{{ route('my.quote.withdraw',$quote->id) }}" class="mt-3" onsubmit="return confirm('Rút yêu cầu báo giá này? Lịch sử vẫn được lưu.');">@csrf<button class="btn btn-outline-danger" type="submit">Rút yêu cầu</button></form>
    @endif
    @if($quote->status==='sent')<p>Báo giá có hiệu lực đến {{ $quote->valid_until }}.</p><form method="post" action="{{ route('my.quote.accept',$quote->id) }}">@csrf<button class="btn-editorial dark" type="submit">Chấp nhận báo giá</button></form>
    @elseif($quote->status==='accepted')<p>Đã chấp nhận. Nhân viên đang lập hợp đồng.</p>@endif
</div>
@endsection
