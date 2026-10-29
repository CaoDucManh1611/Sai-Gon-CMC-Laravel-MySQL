@if($approvalHistory->isNotEmpty())
    <section class="mb-5" aria-labelledby="approval-history-heading">
        <h2 class="h4" id="approval-history-heading">{{ ($showAllLink ?? true) ? 'Lịch sử xử lý gần đây' : 'Các quyết định và phản hồi' }}</h2>
        @if($showAllLink ?? true)<a class="btn btn-outline-dark btn-sm mb-2" href="{{ route('staff.approval-history') }}">Xem toàn bộ lịch sử</a>@endif
        <div class="staff-record-list">
            @foreach($approvalHistory as $event)
                @php
                    $subjectLabels = [
                        'quote_discount' => 'Chiết khấu báo giá', 'contract' => 'Hợp đồng',
                        'catalog_change' => 'Thay đổi danh mục', 'purchase_order' => 'Đề xuất nhập hàng',
                        'stocktake' => 'Kiểm kê', 'report' => 'Báo cáo',
                    ];
                    $decisionLabels = [
                        'approved' => 'Đã duyệt', 'rejected' => 'Từ chối', 'changes_requested' => 'Yêu cầu sửa',
                        'customer_changes_requested' => 'Khách yêu cầu sửa',
                    ];
                @endphp
                <details class="order-card">
                    <summary>
                        <strong>{{ $subjectLabels[$event->subject_type] ?? $event->subject_type }} #{{ $event->subject_id }}</strong>
                        · {{ $decisionLabels[$event->decision] ?? $event->decision }}
                        · {{ $event->actor_name }}
                        · {{ \Carbon\Carbon::parse($event->decided_at)->format('d/m/Y H:i:s') }}
                    </summary>
                    <div class="pt-2">
                        <p>Trạng thái: {{ $event->status_from }} → {{ $event->status_to }}</p>
                        @if($event->reason)<p>Lý do: {{ $event->reason }}</p>@endif
                        <details>
                            <summary>Nội dung hồ sơ tại thời điểm quyết định</summary>
                            <pre class="small text-wrap mt-2">{{ json_encode(json_decode($event->content_snapshot, true, flags: JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                        <small class="text-muted">SHA-256: {{ $event->content_hash }}</small>
                    </div>
                </details>
            @endforeach
        </div>
    </section>
@endif
