@extends('layouts.staff')
@section('title', 'Lịch sử phê duyệt | Sài Gòn CMC')
@section('content')
    <div class="container-fluid container-wide pb-5">
        <div class="page-intro">
            <h1 class="page-title">Lịch sử phê duyệt</h1>
            <p class="page-subtitle">Tra cứu người quyết định, trạng thái, lý do và nội dung hồ sơ tại thời điểm xử lý.</p>
        </div>
        @if($approvalHistory->isEmpty())
            <p>Chưa có quyết định phê duyệt nào.</p>
        @else
            @include('staff.partials.approval-history', ['showAllLink' => false])
        @endif
        {{ $approvalHistory->links() }}
    </div>
@endsection
