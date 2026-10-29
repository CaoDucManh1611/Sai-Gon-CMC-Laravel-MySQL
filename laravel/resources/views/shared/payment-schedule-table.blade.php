@if(!empty($scheduleRows))
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Đợt</th><th>Mốc / hạn</th><th>Phải trả</th><th>Đã phân bổ</th><th>Còn lại</th><th>Trạng thái</th></tr></thead>
            <tbody>
                @foreach($scheduleRows as $scheduleRow)
                    <tr>
                        <td>{{ $scheduleRow->sequence }}. {{ $scheduleRow->label }}</td>
                        <td>
                            {{ ['on_signing' => 'Khi ký hợp đồng', 'before_issue' => 'Trước khi xuất', 'after_handover' => 'Sau bàn giao', 'fixed_date' => 'Ngày cố định'][$scheduleRow->trigger] ?? $scheduleRow->trigger }} · {{ $scheduleRow->due_on ?: 'Chưa kích hoạt' }}
                            @if($scheduleRow->needs_schedule_confirmation)
                                <strong class="d-block text-warning-emphasis">Cần xác nhận hạn theo hợp đồng đã ký</strong>
                            @endif
                        </td>
                        <td>{{ number_format((float) $scheduleRow->effective_amount, 2, ',', '.') }} ₫</td>
                        <td>{{ number_format((float) $scheduleRow->paid_amount, 2, ',', '.') }} ₫</td>
                        <td>{{ number_format((float) $scheduleRow->remaining_amount, 2, ',', '.') }} ₫</td>
                        <td>{{ $scheduleRow->display_status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
