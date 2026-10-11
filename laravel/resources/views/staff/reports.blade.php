@extends('layouts.staff')
@section('title','Báo cáo | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5"><div class="page-intro"><h1 class="page-title">Báo cáo thống kê</h1><p class="page-subtitle">Chọn kỳ báo cáo để tổng hợp số liệu; mở từng bản ghi để xem hoặc xuất chứng từ.</p></div>
@if($allowedTypes)<form method="post" action="{{ route('staff.reports.create') }}" class="order-card d-flex flex-wrap gap-3 align-items-end">@csrf<label>Loại báo cáo<select class="form-select" name="type">@foreach($allowedTypes as $type)<option value="{{ $type }}">{{ ['sales'=>'Kinh doanh','inventory'=>'Tồn kho','finance'=>'Tài chính','logistics'=>'Giao nhận'][$type] }}</option>@endforeach</select></label><label>Từ ngày<input class="form-control staff-date-display" name="from_date_display" inputmode="numeric" autocomplete="off" placeholder="dd/mm/yyyy" aria-label="Từ ngày, ngày tháng năm" required><input type="hidden" name="from_date"></label><label>Đến ngày<input class="form-control staff-date-display" name="to_date_display" inputmode="numeric" autocomplete="off" placeholder="dd/mm/yyyy" aria-label="Đến ngày, ngày tháng năm" required><input type="hidden" name="to_date"></label><button class="btn btn-dark">Lập báo cáo</button></form>@endif
<h2 class="staff-list-title">Báo cáo đã lập</h2><div class="staff-record-list staff-report-list" tabindex="0" role="region" aria-label="Danh sách báo cáo">
@php($reportLabels=['finance'=>'Tài chính','inventory'=>'Tồn kho','sales'=>'Kinh doanh','logistics'=>'Giao nhận'])
@php($reportStatuses=['pending'=>'Chờ duyệt','approved'=>'Đã duyệt','rejected'=>'Cần đối soát'])
@forelse($reports as $report)<details class="order-card"><summary><strong>{{ $report->code }}</strong> · {{ $reportLabels[$report->type] ?? $report->type }} · {{ \Carbon\Carbon::parse($report->from_date)->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($report->to_date)->format('d/m/Y') }} · {{ $reportStatuses[$report->status] ?? $report->status }}</summary><div class="pt-3">@if($report->review_note)<p>{{ $report->review_note }}</p>@endif @if($report->status==='pending' && in_array(auth()->user()->role,['manager','admin']))<form method="post" action="{{ route('staff.reports.review',$report->id) }}">@csrf<label>Ghi chú<input class="form-control" name="note"></label><button class="btn btn-dark mt-2" name="decision" value="approved">Phê duyệt</button><button class="btn btn-outline-danger mt-2" name="decision" value="rejected">Yêu cầu đối soát</button></form>@endif @if($report->status==='approved')<a class="btn btn-outline-dark mt-2" href="{{ route('staff.reports.export',$report->id) }}">Tải CSV cho Excel</a><a class="btn btn-outline-dark mt-2" href="{{ route('staff.reports.print',$report->id) }}">In / Lưu PDF</a>@endif</div></details>@empty<p>Chưa có báo cáo.</p>@endforelse
</div>
{{ $reports->links() }}</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[action="{{ route('staff.reports.create') }}"]');
    if (!form) return;
    const toIso = (text) => {
        const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(text.trim());
        if (!match) return '';
        const [, day, month, year] = match;
        const date = new Date(Number(year), Number(month) - 1, Number(day));
        return date.getFullYear() === Number(year) && date.getMonth() === Number(month) - 1 && date.getDate() === Number(day)
            ? `${year}-${month}-${day}` : '';
    };
    for (const field of form.querySelectorAll('.staff-date-display')) {
        field.addEventListener('input', () => field.setCustomValidity(''));
    }
    form.addEventListener('submit', (event) => {
        for (const field of form.querySelectorAll('.staff-date-display')) {
            const iso = toIso(field.value);
            if (!iso) {
                event.preventDefault();
                field.setCustomValidity('Nhập ngày hợp lệ theo dạng dd/mm/yyyy.');
                field.reportValidity();
                return;
            }
            form.elements[field.name.replace('_display', '')].value = iso;
        }
        if (form.elements.from_date.value > form.elements.to_date.value) {
            event.preventDefault();
            const field = form.elements.to_date_display;
            field.setCustomValidity('Ngày kết thúc phải từ ngày bắt đầu trở đi.');
            field.reportValidity();
        }
    });
});
</script>
@endsection
