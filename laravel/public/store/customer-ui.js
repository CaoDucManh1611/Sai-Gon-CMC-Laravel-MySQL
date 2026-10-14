document.addEventListener('DOMContentLoaded', () => {
    const filter = document.querySelector('[data-debt-filter]');
    if (filter) {
        const table = filter.nextElementSibling?.querySelector('tbody');
        const rows = table ? [...table.rows] : [];
        const search = filter.querySelector('input[type="search"]');
        const status = filter.querySelector('select[aria-label="Trạng thái công nợ"]');
        const sort = filter.querySelector('select[aria-label="Sắp xếp công nợ"]');
        const count = filter.querySelector('output');
        const empty = filter.parentElement.querySelector('.customer-debt-empty');
        rows.forEach((row) => {
            const cell = row.cells[3];
            if (!cell) return;
            const amount = cell.textContent.trim();
            const balance = document.createElement('span');
            balance.className = 'customer-debt-balance ' + (/^0(?:[.,]0+)?\s*₫$/.test(amount) ? 'is-settled' : 'is-open');
            balance.textContent = amount;
            cell.replaceChildren(balance);
        });
        const apply = () => {
            const query = search.value.trim().toLocaleLowerCase('vi');
            let visible = 0;
            rows.forEach((row) => {
                const settled = !!row.querySelector('.customer-debt-balance.is-settled');
                const show = row.textContent.toLocaleLowerCase('vi').includes(query) && (!status.value || (status.value === 'settled' ? settled : !settled));
                row.hidden = !show;
                if (show) visible++;
            });
            count.textContent = `${visible}/${rows.length} khoản`;
            empty.hidden = visible !== 0;
        };
        search.addEventListener('input', apply);
        status.addEventListener('change', apply);
        sort.addEventListener('change', () => {
            (sort.value === 'old' ? [...rows].reverse() : rows).forEach((row) => table.append(row));
            apply();
        });
        filter.querySelector('.customer-debt-clear').addEventListener('click', () => {
            search.value = '';
            status.value = '';
            sort.value = 'new';
            sort.dispatchEvent(new Event('change'));
            search.focus();
        });
        apply();
    }

    const estimate = document.querySelector('form.order-card input[name="area"]');
    if (estimate) {
        const fields = [
            [estimate, 'Vui lòng nhập diện tích sàn (m²).', 'Diện tích sàn phải từ 0,01 đến 10.000 m².'],
            [estimate.form.querySelector('input[name="floors"]'), 'Vui lòng nhập số tầng.', 'Số tầng phải từ 1 đến 50.']
        ];
        fields.forEach(([input, missing, range]) => {
            if (!input) return;
            input.addEventListener('invalid', () => input.setCustomValidity(input.validity.valueMissing ? missing : range));
            input.addEventListener('input', () => input.setCustomValidity(''));
        });
    }
});
