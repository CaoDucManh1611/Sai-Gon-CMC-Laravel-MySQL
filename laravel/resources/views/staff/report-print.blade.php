<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $report->code }} | Sài Gòn CMC</title>
<style>body{font:14px Arial,sans-serif;color:#222;margin:35px}h1{font-size:26px}h2{font-size:18px;margin-top:30px}table{width:100%;border-collapse:collapse;margin:14px 0 25px}th,td{border:1px solid #ccc;padding:8px;text-align:left}th{background:#eee}.actions{margin-bottom:25px}@media print{.actions{display:none}body{margin:12mm}@page{size:A4;margin:12mm}}</style>
</head>
<body>
@php
    $reportTypes = ['finance' => 'Tài chính', 'inventory' => 'Tồn kho', 'sales' => 'Kinh doanh', 'logistics' => 'Giao nhận'];
    $sections = ['payments' => 'Thanh toán', 'debts' => 'Công nợ', 'stock' => 'Tồn kho', 'movements' => 'Biến động kho', 'orders' => 'Đơn hàng', 'contracts' => 'Hợp đồng', 'shipments' => 'Chuyến giao hàng', 'returns' => 'Hàng trả'];
    $fields = ['code' => 'Mã chứng từ', 'status' => 'Trạng thái', 'total' => 'Tổng tiền', 'paid' => 'Đã thanh toán', 'amount' => 'Giá trị', 'method' => 'Phương thức', 'transactions' => 'Số giao dịch', 'direction' => 'Loại công nợ', 'warehouse' => 'Kho', 'product' => 'Mã vật liệu', 'quantity' => 'Số lượng', 'minimum_stock' => 'Tồn tối thiểu', 'product_id' => 'Mã vật liệu', 'change' => 'Thay đổi', 'created_at' => 'Ngày tạo', 'carrier_name' => 'Đơn vị vận chuyển', 'delivered_at' => 'Ngày giao', 'credit_amount' => 'Giá trị hoàn'];
@endphp
<div class="actions"><button onclick="window.print()">In hoặc lưu PDF</button> <a href="{{ route('staff.reports.index') }}">Quay lại</a></div>
<header><strong>SÀI GÒN CMC — VẬT LIỆU XÂY DỰNG</strong><h1>Báo cáo {{ $report->code }}</h1><p>Loại: {{ $reportTypes[$report->type] ?? 'Báo cáo' }} · Kỳ: {{ $report->from_date }} đến {{ $report->to_date }} · Đã phê duyệt</p></header>
@foreach($snapshot as $section => $rows)
<h2>{{ $sections[$section] ?? 'Dữ liệu' }}</h2>
@if(count($rows))
<table>
<thead><tr>
@foreach(array_keys((array) $rows[0]) as $field)
<th>{{ $fields[$field] ?? 'Thông tin' }}</th>
@endforeach
</tr></thead>
<tbody>
@foreach($rows as $row)
<tr>
@foreach((array) $row as $field => $value)
<td>@if(in_array($field, ['amount', 'paid', 'total', 'credit_amount'])){{ number_format((float) $value, 0, ',', '.') }} ₫@elseif(in_array($field, ['quantity', 'minimum_stock', 'change'])){{ \App\Support\UiFormat::quantity($value) }}@elseif($field === 'status'){{ \App\Support\UiFormat::status($value) }}@elseif($field === 'direction'){{ $value === 'receivable' ? 'Phải thu' : 'Phải trả' }}@elseif($field === 'method'){{ ['cash' => 'Tiền mặt', 'bank_transfer' => 'Chuyển khoản'][$value] ?? 'Khác' }}@else{{ $value }}@endif</td>
@endforeach
</tr>
@endforeach
</tbody></table>
@else
<p>Không có dữ liệu trong kỳ.</p>
@endif
@endforeach
</body></html>
