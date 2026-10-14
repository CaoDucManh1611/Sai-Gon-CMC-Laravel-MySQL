@extends('layouts.staff')
@section('title','Báo cáo | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5"><div class="page-intro"><h1 class="page-title">Báo cáo thống kê</h1><p class="page-subtitle">Chọn kỳ báo cáo để tổng hợp số liệu; mở từng bản ghi để xem hoặc xuất chứng từ.</p></div>
@if($allowedTypes)<form method="post" action="{{ route('staff.reports.create') }}" class="order-card d-flex flex-wrap gap-3 align-items-end">@csrf<label>Loại báo cáo<select class="form-select" name="type">@foreach($allowedTypes as $type)<option value="{{ $type }}">{{ ['sales'=>'Kinh doanh','inventory'=>'Tồn kho','finance'=>'Tài chính','logistics'=>'Giao nhận'][$type] }}</option>@endforeach</select></label><label>Kỳ báo cáo<select class="form-select" name="period_preset"><option value="custom">Tùy chọn ngày</option><option value="day">Hôm nay</option><option value="month">Tháng này</option><option value="quarter">Quý này</option><option value="year">Năm này</option></select></label><label>Từ ngày<input class="form-control staff-date-display" name="from_date_display" inputmode="numeric" autocomplete="off" placeholder="dd/mm/yyyy" aria-label="Từ ngày, ngày tháng năm" required><input type="hidden" name="from_date"></label><label>Đến ngày<input class="form-control staff-date-display" name="to_date_display" inputmode="numeric" autocomplete="off" placeholder="dd/mm/yyyy" aria-label="Đến ngày, ngày tháng năm" required><input type="hidden" name="to_date"></label><label>Nhóm vật liệu<select class="form-select" name="category_id"><option value="">Tất cả</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label><label>Khách hàng<select class="form-select" name="customer_id"><option value="">Tất cả</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach</select></label><label>Nhân viên xác nhận đơn<select class="form-select" name="staff_id"><option value="">Tất cả</option>@foreach($staffMembers as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach</select></label><label>Nhà cung cấp vật liệu<select class="form-select" name="supplier_id"><option value="">Tất cả</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label><button class="btn btn-dark">Lập báo cáo</button></form>@if($errors->has('filter'))<p class="text-danger">{{ $errors->first('filter') }}</p>@endif @endif
<h2 class="staff-list-title">Báo cáo đã lập</h2><div class="staff-record-list staff-report-list" tabindex="0" role="region" aria-label="Danh sách báo cáo">
@php($reportLabels=['finance'=>'Tài chính','inventory'=>'Tồn kho','sales'=>'Kinh doanh','logistics'=>'Giao nhận'])
@php($reportStatuses=['pending'=>'Chờ duyệt','approved'=>'Đã duyệt','rejected'=>'Cần đối soát'])
@forelse($reports as $report)<details class="order-card"><summary><strong>{{ $report->code }}</strong> · {{ $reportLabels[$report->type] ?? $report->type }} · {{ \Carbon\Carbon::parse($report->from_date)->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($report->to_date)->format('d/m/Y') }} · {{ $reportStatuses[$report->status] ?? $report->status }}</summary><div class="pt-3">@if($report->review_note)<p>{{ $report->review_note }}</p>@endif @if($report->status==='pending' && in_array(auth()->user()->role,['manager','admin']))<form method="post" action="{{ route('staff.reports.review',$report->id) }}">@csrf<label>Ghi chú<input class="form-control" name="note"></label><button class="btn btn-dark mt-2" name="decision" value="approved">Phê duyệt</button><button class="btn btn-outline-danger mt-2" name="decision" value="rejected">Yêu cầu đối soát</button></form>@endif @if($report->status==='approved')<a class="btn btn-outline-dark mt-2" href="{{ route('staff.reports.export',$report->id) }}">Tải Excel</a><a class="btn btn-outline-dark mt-2" href="{{ route('staff.reports.print',$report->id) }}">Xem bản in</a><a class="btn btn-outline-dark mt-2" href="{{ route('staff.reports.pdf',$report->id) }}">Tải PDF</a>@endif</div></details>@empty<p>Chưa có báo cáo.</p>@endforelse
</div>
{{ $reports->links() }}</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[action="{{ route('staff.reports.create') }}"]');
    if (!form) return;
    const period = form.elements.period_preset;
    period.addEventListener('change', () => {
        if (period.value === 'custom') return;
        const today = new Date();
        const year = today.getFullYear();
        const month = today.getMonth();
        const start = period.value === 'year' ? new Date(year, 0, 1)
            : period.value === 'quarter' ? new Date(year, Math.floor(month / 3) * 3, 1)
            : period.value === 'month' ? new Date(year, month, 1) : today;
        const end = period.value === 'year' ? new Date(year, 11, 31)
            : period.value === 'quarter' ? new Date(year, Math.floor(month / 3) * 3 + 3, 0)
            : period.value === 'month' ? new Date(year, month + 1, 0) : today;
        const display = (date) => [String(date.getDate()).padStart(2, '0'), String(date.getMonth() + 1).padStart(2, '0'), date.getFullYear()].join('/');
        form.elements.from_date_display.value = display(start);
        form.elements.to_date_display.value = display(end);
    });
    const reportType = form.elements.type;
    const updateFilters = () => {
        const inventory = reportType.value === 'inventory';
        for (const name of ['customer_id', 'staff_id']) {
            form.elements[name].disabled = inventory;
            if (inventory) form.elements[name].value = '';
        }
    };
    reportType.addEventListener('change', updateFilters);
    updateFilters();
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
