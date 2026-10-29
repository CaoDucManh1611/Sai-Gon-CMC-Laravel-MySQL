@extends('layouts.store')
@section('title', 'Đối chiếu công nợ | Sài Gòn CMC')
@section('content')
<main class="container container-wide py-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1>Đối chiếu công nợ</h1><p class="text-secondary mb-0">Xem các bản đối chiếu kế toán đã gửi và phản hồi theo từng kỳ.</p></div><a class="btn btn-outline-dark" href="{{ route('profile') }}">Tài khoản của tôi</a></div>
    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Kỳ</th><th>Phiên bản</th><th>Dư cuối kỳ</th><th>Trạng thái</th><th></th></tr></thead><tbody>
        @forelse($reconciliations as $row)<tr><td>{{ $row->period_start }} – {{ $row->as_of_date }}</td><td>v{{ $row->version_number }}</td><td>{{ number_format($row->closing_balance, 0, ',', '.') }} ₫</td><td>{{ \App\Support\UiFormat::status($row->status) }}@if($row->status === 'resolved' && ! $row->customer_confirmed_at)<small class="d-block text-warning-emphasis">Chờ khách xác nhận cách xử lý</small>@endif</td><td><a href="{{ route('my.debt-reconciliations.show', $row->id) }}">Xem chi tiết</a></td></tr>
        @empty<tr><td colspan="5" class="text-center text-secondary py-4">Chưa có bản đối chiếu được gửi.</td></tr>@endforelse
    </tbody></table></div>
    {{ $reconciliations->links() }}
</main>
@endsection
