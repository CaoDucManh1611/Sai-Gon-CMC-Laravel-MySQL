@php
    $allocationPreviewData = collect($scheduleRows)->map(fn ($row) => [
        'sequence' => (int) $row->sequence,
        'label' => (string) $row->label,
        'remaining' => (float) $row->remaining_amount,
    ])->values();
@endphp
@if($allocationPreviewData->isNotEmpty())
    <div class="small w-100" data-payment-allocation-preview data-installments="{{ $allocationPreviewData->toJson() }}">
        <strong>Phân bổ dự kiến theo thứ tự:</strong>
        <ul class="mb-1 ps-3" data-allocation-preview-list><li>Nhập số tiền để xem từng đợt.</li></ul>
        <span aria-live="polite" data-allocation-preview-status></span>
    </div>
@endif
@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-payment-allocation-preview]').forEach((preview) => {
                const form = preview.closest('form');
                const amountInput = form.querySelector('[data-payment-amount]');
                const list = preview.querySelector('[data-allocation-preview-list]');
                const status = preview.querySelector('[data-allocation-preview-status]');
                const installments = JSON.parse(preview.dataset.installments);
                const format = (amount) => amount.toLocaleString('vi-VN', {minimumFractionDigits: 2, maximumFractionDigits: 2});

                amountInput.addEventListener('input', () => {
                    let remaining = Number(amountInput.value || 0);
                    list.replaceChildren();
                    if (remaining <= 0) {
                        list.innerHTML = '<li>Nhập số tiền để xem từng đợt.</li>';
                        status.textContent = '';
                        return;
                    }
                    installments.forEach((installment) => {
                        const available = Math.max(0, Number(installment.remaining));
                        const allocated = Math.min(remaining, available);
                        if (allocated <= 0) return;
                        const item = document.createElement('li');
                        item.textContent = `${installment.sequence}. ${installment.label}: ${format(allocated)} ₫`;
                        list.append(item);
                        remaining -= allocated;
                    });
                    status.textContent = remaining > 0.004
                        ? `Vượt lịch còn phải thu ${format(remaining)} ₫; không thể ghi nhận.`
                        : 'Số tiền sẽ được phân bổ hết vào lịch trên.';
                });
            });
        });
    </script>
@endonce
