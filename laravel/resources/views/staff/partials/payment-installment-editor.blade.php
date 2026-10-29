@php
    $initialInstallments = !empty($initialInstallments) ? $initialInstallments : [[
        'sequence' => 1, 'label' => 'Đợt thanh toán 1', 'amount' => '', 'trigger' => 'before_issue',
        'due_on' => null, 'days_after_trigger' => null, 'required_before_issue' => true,
    ]];
    $scheduleTotal = $scheduleTotal ?? '0.00';
    $editorId = $editorId ?? 'payment-schedule-'.uniqid();
@endphp
<div id="{{ $editorId }}" class="payment-schedule-editor border rounded p-3 mt-3" data-payment-schedule-editor>
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div>
            <strong>Lịch thanh toán theo đợt</strong>
            <div class="small text-secondary">Tổng tiền các đợt phải bằng giá trị đơn hàng; các đợt được phân bổ theo thứ tự.</div>
        </div>
        <label class="form-label mb-0">Tổng phải thu trước khi xuất
            <input class="form-control form-control-sm" name="deposit_required" value="0.00" readonly data-required-total>
        </label>
    </div>
    <div class="d-grid gap-2" data-installment-rows>
        @foreach($initialInstallments as $index => $installment)
            <div class="row g-2 align-items-end border-bottom pb-2" data-installment-row>
                <div class="col-12 col-md-2"><label class="form-label">Đợt<input class="form-control form-control-sm" type="number" min="1" name="installments[{{ $index }}][sequence]" value="{{ data_get($installment, 'sequence', $index + 1) }}" required data-sequence></label></div>
                <div class="col-12 col-md-3"><label class="form-label">Tên đợt<input class="form-control form-control-sm" name="installments[{{ $index }}][label]" value="{{ data_get($installment, 'label') }}" maxlength="150" required></label></div>
                <div class="col-12 col-md-2"><label class="form-label">Số tiền<input class="form-control form-control-sm" type="number" name="installments[{{ $index }}][amount]" value="{{ data_get($installment, 'amount') }}" min="0.01" step="0.01" required data-installment-amount></label></div>
                <div class="col-12 col-md-2"><label class="form-label">Mốc thanh toán
                    <select class="form-select form-select-sm" name="installments[{{ $index }}][trigger]" required data-installment-trigger>
                        <option value="on_signing" @selected(data_get($installment, 'trigger') === 'on_signing')>Khi ký hợp đồng</option>
                        <option value="before_issue" @selected(data_get($installment, 'trigger', 'before_issue') === 'before_issue')>Trước khi xuất</option>
                        <option value="after_handover" @selected(data_get($installment, 'trigger') === 'after_handover')>Sau bàn giao</option>
                        <option value="fixed_date" @selected(data_get($installment, 'trigger') === 'fixed_date')>Ngày cố định</option>
                    </select>
                </label></div>
                <div class="col-12 col-md-2" data-date-field><label class="form-label">Ngày đến hạn<input class="form-control form-control-sm" type="date" name="installments[{{ $index }}][due_on]" value="{{ data_get($installment, 'due_on') }}"></label></div>
                <div class="col-12 col-md-2" data-days-field><label class="form-label">Số ngày sau mốc<input class="form-control form-control-sm" type="number" min="0" name="installments[{{ $index }}][days_after_trigger]" value="{{ data_get($installment, 'days_after_trigger') }}"></label></div>
                <div class="col-12 col-md-2"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" value="1" name="installments[{{ $index }}][required_before_issue]" @checked((bool) data_get($installment, 'required_before_issue')) data-required-before-issue><span class="form-check-label">Bắt buộc trước xuất</span></label></div>
                <div class="col-12 col-md-1"><button class="btn btn-sm btn-outline-danger mb-2" type="button" data-remove-installment aria-label="Xóa đợt">Xóa</button></div>
            </div>
        @endforeach
    </div>
    <button class="btn btn-sm btn-outline-dark mt-3" type="button" data-add-installment>Thêm đợt thanh toán</button>
    <template data-installment-template>
        <div class="row g-2 align-items-end border-bottom pb-2" data-installment-row>
            <div class="col-12 col-md-2"><label class="form-label">Đợt<input class="form-control form-control-sm" type="number" min="1" name="installments[__INDEX__][sequence]" value="__SEQUENCE__" required data-sequence></label></div>
            <div class="col-12 col-md-3"><label class="form-label">Tên đợt<input class="form-control form-control-sm" name="installments[__INDEX__][label]" value="Đợt thanh toán __SEQUENCE__" maxlength="150" required></label></div>
            <div class="col-12 col-md-2"><label class="form-label">Số tiền<input class="form-control form-control-sm" type="number" name="installments[__INDEX__][amount]" min="0.01" step="0.01" required data-installment-amount></label></div>
            <div class="col-12 col-md-2"><label class="form-label">Mốc thanh toán<select class="form-select form-select-sm" name="installments[__INDEX__][trigger]" required data-installment-trigger><option value="on_signing">Khi ký hợp đồng</option><option value="before_issue" selected>Trước khi xuất</option><option value="after_handover">Sau bàn giao</option><option value="fixed_date">Ngày cố định</option></select></label></div>
            <div class="col-12 col-md-2" data-date-field><label class="form-label">Ngày đến hạn<input class="form-control form-control-sm" type="date" name="installments[__INDEX__][due_on]"></label></div>
            <div class="col-12 col-md-2" data-days-field><label class="form-label">Số ngày sau mốc<input class="form-control form-control-sm" type="number" min="0" name="installments[__INDEX__][days_after_trigger]"></label></div>
            <div class="col-12 col-md-2"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" value="1" name="installments[__INDEX__][required_before_issue]" checked data-required-before-issue><span class="form-check-label">Bắt buộc trước xuất</span></label></div>
            <div class="col-12 col-md-1"><button class="btn btn-sm btn-outline-danger mb-2" type="button" data-remove-installment aria-label="Xóa đợt">Xóa</button></div>
        </div>
    </template>
</div>
@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-payment-schedule-editor]').forEach((editor) => {
            const rows = editor.querySelector('[data-installment-rows]');
            const template = editor.querySelector('[data-installment-template]');
            const requiredTotal = editor.querySelector('[data-required-total]');

            const renumber = () => {
                rows.querySelectorAll('[data-installment-row]').forEach((row, index) => {
                    row.querySelectorAll('[name]').forEach((input) => {
                        input.name = input.name.replace(/installments\[\d+\]/, `installments[${index}]`);
                    });
                    row.querySelector('[data-sequence]').value = index + 1;
                    const label = row.querySelector('input[name$="[label]"]');
                    if (label.value.startsWith('Đợt thanh toán ')) label.value = `Đợt thanh toán ${index + 1}`;
                });
            };
            const updateRow = (row) => {
                const trigger = row.querySelector('[data-installment-trigger]').value;
                const dateField = row.querySelector('[data-date-field]');
                const dateInput = dateField.querySelector('input');
                const daysField = row.querySelector('[data-days-field]');
                const daysInput = daysField.querySelector('input');
                const requiredBeforeIssue = row.querySelector('[data-required-before-issue]');
                dateField.hidden = trigger !== 'fixed_date';
                dateInput.required = trigger === 'fixed_date';
                daysField.hidden = !['after_handover', 'on_signing'].includes(trigger);
                daysInput.required = trigger === 'after_handover';
                if (trigger === 'after_handover') requiredBeforeIssue.checked = false;
                requiredBeforeIssue.disabled = trigger === 'after_handover';
            };
            const refresh = () => {
                let total = 0;
                rows.querySelectorAll('[data-installment-row]').forEach((row) => {
                    updateRow(row);
                    if (row.querySelector('[data-required-before-issue]').checked) {
                        total += Number(row.querySelector('[data-installment-amount]').value || 0);
                    }
                });
                requiredTotal.value = total.toFixed(2);
            };

            editor.addEventListener('input', refresh);
            editor.addEventListener('change', refresh);
            editor.addEventListener('click', (event) => {
                if (event.target.closest('[data-add-installment]')) {
                    const index = rows.querySelectorAll('[data-installment-row]').length;
                    const html = template.innerHTML.replaceAll('__INDEX__', index).replaceAll('__SEQUENCE__', index + 1);
                    rows.insertAdjacentHTML('beforeend', html);
                    renumber();
                    refresh();
                }
                if (event.target.closest('[data-remove-installment]')) {
                    event.target.closest('[data-installment-row]').remove();
                    renumber();
                    refresh();
                }
            });
            renumber();
            refresh();
        });
        });
    </script>
@endonce
