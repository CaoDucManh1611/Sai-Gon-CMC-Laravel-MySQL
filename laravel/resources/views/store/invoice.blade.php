@extends('layouts.store')
@section('title',$invoice->code.' | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5">
    <div class="page-intro"><a href="{{ route('my.orders') }}">Đơn hàng của tôi</a><h1 class="page-title">Hóa đơn {{ $invoice->code }}</h1><p class="page-subtitle">Đơn {{ $invoice->order_code }} · Lập ngày {{ $invoice->issued_at }}</p></div>
    <p><strong>Địa chỉ giao:</strong> {{ $invoice->delivery_address }}</p>
    <div class="table-responsive"><table class="table"><thead><tr><th>Vật liệu</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead><tbody>@foreach($items as $item)<tr><td>{{ $item->name }}</td><td>{{ \App\Support\UiFormat::quantity($item->quantity) }} {{ $item->unit }}</td><td>{{ number_format($item->unit_price,0,',','.') }} ₫</td><td>{{ number_format($item->line_total,0,',','.') }} ₫</td></tr>@endforeach</tbody></table></div>
    @php($vat = bcdiv((string) $invoice->total, '11', 2))
    <div class="text-end"><p>Giá trước VAT: {{ number_format(bcsub((string) $invoice->total, $vat, 2),0,',','.') }} ₫</p><p>VAT 10% (đã gồm trong giá): {{ number_format($vat,0,',','.') }} ₫</p><p>Tổng giá trị: <strong>{{ number_format($invoice->total,0,',','.') }} ₫</strong></p><p>Đã thanh toán khi lập: {{ number_format($invoice->paid_at_issue,0,',','.') }} ₫</p><button class="btn btn-outline-dark" type="button" onclick="window.print()">In hóa đơn</button></div>
    <p class="demo-note">Chứng từ bán hàng của hệ thống; việc phát hành hóa đơn thuế thực tế cần cấu hình riêng theo quy định hiện hành.</p>
</div>
@endsection
