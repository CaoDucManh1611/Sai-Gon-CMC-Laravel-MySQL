<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Hợp đồng {{ $contract->code }}</title>
    <style>
        @page { margin: 32px 38px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.6; color: #202b28; }
        h1 { font-size: 17px; text-align: center; margin: 20px 0 4px; }
        h2 { font-size: 12px; margin: 18px 0 6px; }
        p { margin: 5px 0; }
        .center { text-align: center; }
        .brand { font-size: 13px; font-weight: bold; border-bottom: 2px solid #173e35; padding-bottom: 8px; }
        .terms { white-space: pre-wrap; overflow-wrap: break-word; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; table-layout: fixed; }
        th, td { border: 1px solid #cbd5d1; padding: 7px 5px; overflow-wrap: break-word; }
        th { background: #edf3f0; }
        .number { text-align: right; }
        tr { page-break-inside: avoid; }
        .signatures { page-break-inside: avoid; margin-top: 24px; }
        .signatures td { border: 0; text-align: center; vertical-align: top; height: 105px; }
        .note { color: #57665f; font-size: 9px; }
    </style>
</head>
<body>
    <div class="brand">SÀI GÒN CMC · VẬT LIỆU XÂY DỰNG</div>
    <h1>HỢP ĐỒNG ĐẶT HÀNG VẬT LIỆU XÂY DỰNG</h1>
    <p class="center">Số: {{ $contract->code }} · Ngày lập: {{ \Illuminate\Support\Carbon::parse($contract->created_at)->format('d/m/Y') }}</p>
    <h2>1. Thông tin các bên</h2>
    <p><strong>Bên bán:</strong> Sài Gòn CMC</p>
    <p><strong>Bên mua:</strong> {{ $customer->name }}</p>
    <p><strong>Điện thoại:</strong> {{ $customer->phone ?: '................................' }} · <strong>Email:</strong> {{ $customer->email ?: '................................' }}</p>
    <p><strong>Địa chỉ khách hàng:</strong> {{ $customer->address ?: '................................' }}</p>
    <p><strong>Địa chỉ giao hàng:</strong> {{ $order?->delivery_address ?? $quote?->delivery_address }}</p>
    <p><strong>Đơn hàng:</strong> {{ $order?->code ?: '—' }} · <strong>Báo giá:</strong> {{ $quote?->code ?: '—' }}</p>
    <h2>2. Danh mục vật liệu đặt hàng</h2>
    <table>
        <thead><tr><th style="width: 32%">Vật liệu / quy cách</th><th style="width: 10%">Đơn vị</th><th style="width: 12%">Số lượng</th><th style="width: 20%">Đơn giá (đ)</th><th style="width: 26%">Thành tiền (đ)</th></tr></thead>
        <tbody>@foreach($items as $item)<tr><td>{{ $item->name }}<br>{{ $item->specification }}</td><td>{{ $item->unit }}</td><td class="number">{{ \App\Support\UiFormat::quantity($item->quantity) }}</td><td class="number">{{ number_format($item->unit_price, 0, ',', '.') }}</td><td class="number">{{ number_format($item->line_total, 0, ',', '.') }}</td></tr>@endforeach</tbody>
    </table>
    <p class="number"><strong>Tổng giá trị hợp đồng: {{ number_format($contract->total, 0, ',', '.') }} đồng</strong></p>
    <h2>3. Thanh toán</h2>
    <p><strong>Tiền đặt cọc yêu cầu:</strong> {{ number_format($contract->deposit_required, 0, ',', '.') }} đồng.</p>
    <div class="terms">{{ $contract->payment_terms }}</div>
    <h2>4. Giao hàng</h2>
    <div class="terms">{{ $contract->delivery_terms }}</div>
    <p>Ngày khách hàng xác nhận nội dung: {{ \Illuminate\Support\Carbon::parse($contract->customer_confirmed_at)->format('d/m/Y H:i') }}.</p>
    <table class="signatures"><tr><td><strong>ĐẠI DIỆN BÊN BÁN</strong><br>(Ký, ghi rõ họ tên)</td><td><strong>ĐẠI DIỆN BÊN MUA</strong><br>(Ký, ghi rõ họ tên)</td></tr></table>
    <p class="note">Bản xuất từ nội dung hợp đồng đã được duyệt và khách hàng xác nhận. Chữ ký được các bên bổ sung khi ký kết.</p>
</body>
</html>
