document.addEventListener('DOMContentLoaded', () => {
    const stateBadge = (label) => {
        const badge = document.createElement('span');
        badge.className = 'staff-state-badge ' + (/đã duyệt|đã nhập|đã giao|hoàn tất|đã xử lý|đã kiểm tra|đã thu hồi/i.test(label) ? 'is-done' : /từ chối|hủy|hỏng/i.test(label) ? 'is-alert' : 'is-pending');
        badge.textContent = label;
        return badge;
    };
    const contractSection = document.querySelector('#contracts');
    contractSection?.querySelector(':scope > form')?.remove();
    contractSection?.closest('.staff-workspace-scroll')?.classList.add('staff-workspace-expanded');
    contractSection?.closest('.staff-panel')?.classList.add('contract-workspace-panel');
    document.querySelectorAll('#contracts details.order-card > summary').forEach((summary) => {
        const code = summary.querySelector('strong');
        if (!code) return;
        const parts = summary.textContent.replace(code.textContent, '').split('·').map((part) => part.trim()).filter(Boolean);
        if (parts.length < 3) return;
        const context = document.createElement('span');
        context.className = 'contract-context';
        context.textContent = parts[0];
        const status = document.createElement('span');
        status.className = 'staff-state-badge ' + (/đã ký|đã duyệt/i.test(parts[1]) ? 'is-done' : /hủy|từ chối|sửa/i.test(parts[1]) ? 'is-alert' : 'is-pending');
        status.textContent = parts[1];
        const amount = document.createElement('b');
        amount.className = 'contract-amount';
        amount.textContent = parts.slice(2).join(' · ');
        summary.replaceChildren(code, context, status, amount);
    });
    document.querySelectorAll('.staff-report-list details.order-card > summary').forEach((summary) => {
        const code = summary.querySelector('strong');
        if (!code) return;
        const parts = summary.textContent.replace(code.textContent, '').split('·').map((part) => part.trim()).filter(Boolean);
        if (parts.length < 2) return;
        const context = document.createElement('span');
        context.className = 'report-context';
        context.textContent = parts.slice(0, -1).join(' · ');
        const status = document.createElement('span');
        status.className = 'staff-state-badge ' + (/đã duyệt/i.test(parts.at(-1)) ? 'is-done' : /đối soát|từ chối/i.test(parts.at(-1)) ? 'is-alert' : 'is-pending');
        status.textContent = parts.at(-1);
        summary.replaceChildren(code, context, status);
    });
    document.querySelectorAll('#finance tbody tr').forEach((row) => {
        const cells = row.querySelectorAll('td');
        const typeCell = cells[2];
        if (!typeCell) return;
        const badge = document.createElement('span');
        badge.className = 'finance-type-badge ' + (/dự kiến/i.test(typeCell.textContent) ? 'is-pending' : /phải trả/i.test(typeCell.textContent) ? 'is-payable' : 'is-receivable');
        badge.textContent = typeCell.textContent.trim();
        typeCell.replaceChildren(badge);
        const balanceCell = cells[5];
        if (balanceCell) {
            const balance = document.createElement('strong');
            balance.className = 'finance-balance ' + (/^0(?:[.,]0+)?\s*₫$/.test(balanceCell.textContent.trim()) ? 'is-settled' : 'is-open');
            balance.textContent = balanceCell.textContent.trim();
            balanceCell.replaceChildren(balance);
        }
        const noteCell = cells[6];
        if (noteCell && !noteCell.querySelector('form')) {
            const note = noteCell.textContent.trim() || (balanceCell?.querySelector('.is-settled') ? 'Đã tất toán' : /dự kiến/i.test(typeCell.textContent) ? 'Chưa phát sinh' : 'Chưa tất toán');
            const label = document.createElement('span');
            label.className = 'finance-note-badge ' + (/đã tất toán/i.test(note) ? 'is-done' : 'is-pending');
            label.textContent = note;
            noteCell.replaceChildren(label);
        }
    });
    document.querySelectorAll('[aria-labelledby="debt-schedule-heading"] details.order-card > summary').forEach((summary) => {
        const parts = summary.textContent.split('·').map((part) => part.trim()).filter(Boolean);
        if (parts.length < 3) return;
        const code = Object.assign(document.createElement('strong'), {textContent: parts[0]});
        const direction = document.createElement('span');
        direction.className = 'finance-type-badge ' + (/phải trả/i.test(parts[1]) ? 'is-payable' : 'is-receivable');
        direction.textContent = parts[1];
        const amount = Object.assign(document.createElement('strong'), {className: 'debt-schedule-amount', textContent: parts[2]});
        summary.replaceChildren(code, direction, amount);
        if (parts[3]) summary.append(Object.assign(document.createElement('small'), {textContent: parts.slice(3).join(' · ')}));
    });
    const purchases = document.querySelector('#purchases');
    if (purchases) {
        purchases.querySelectorAll('details.order-card').forEach((details) => {
            const summary = details.querySelector('summary');
            const code = summary?.querySelector('strong');
            if (!code) return;
            const parts = summary.textContent.replace(code.textContent, '').split('·').map((part) => part.trim()).filter(Boolean);
            if (parts.length < 2) return;
            summary.replaceChildren(code, stateBadge(parts[0]), Object.assign(document.createElement('b'), {className: 'purchase-amount', textContent: parts.slice(1).join(' · ')}));
            const entry = document.createElement('div');
            entry.className = 'purchase-entry';
            details.before(entry);
            entry.append(details);
            const approval = [...details.querySelectorAll('form')].find((form) => form.textContent.includes('Phê duyệt nhập hàng'));
            if (approval) {
                approval.classList.add('purchase-approve-action');
                entry.append(approval);
                if (!details.querySelector(':scope > div')?.textContent.trim()) {
                    details.querySelector(':scope > div')?.remove();
                    summary.addEventListener('click', (event) => event.preventDefault());
                }
            }
        });
        const incidentHeading = [...purchases.children].find((child) => child.matches('h3') && child.textContent.includes('Hàng giao thiếu hoặc hỏng'));
        if (incidentHeading) {
            const panel = document.createElement('section');
            panel.id = 'supplier-incidents';
            panel.className = 'staff-subpanel';
            incidentHeading.before(panel);
            let item = incidentHeading;
            while (item) {
                const next = item.nextElementSibling;
                panel.append(item);
                item = next;
            }
            purchases.after(panel);
        }
    }
    document.querySelectorAll('#supplier-incidents article.order-card').forEach((card) => {
        const code = card.querySelector(':scope > strong');
        const line = [...card.childNodes].find((node) => node.nodeType === Node.TEXT_NODE && node.textContent.includes('·'));
        if (!code || !line) return;
        const parts = line.textContent.split('·').map((part) => part.trim()).filter(Boolean);
        if (parts.length < 3) return;
        const heading = document.createElement('div');
        heading.className = 'staff-record-heading';
        heading.append(code, Object.assign(document.createElement('span'), {textContent: parts.slice(0, -1).join(' · ')}), stateBadge(parts.at(-1)));
        line.remove();
        card.prepend(heading);
    });
    document.querySelectorAll('#orders > .order-card').forEach((card) => {
        const line = card.querySelector(':scope > span');
        const code = line?.querySelector('strong');
        if (!code) return;
        const label = [...line.childNodes].find((node) => node.nodeType === Node.TEXT_NODE && node.textContent.includes('·'));
        if (!label) return;
        const status = label.textContent.split('·').at(-1).trim();
        label.remove();
        const destination = line.querySelector('small');
        const codeCell = document.createElement('span');
        codeCell.className = 'order-record-code';
        codeCell.append(code);
        const statusCell = stateBadge(status);
        statusCell.classList.add('order-record-state');
        const destinationCell = document.createElement('span');
        destinationCell.className = 'order-record-destination';
        if (destination) destinationCell.append(destination);
        card.prepend(codeCell, statusCell, destinationCell);
        line.remove();
    });
    document.querySelectorAll('#returns > .order-card').forEach((card) => {
        const code = card.querySelector(':scope > strong');
        const line = [...card.childNodes].find((node) => node.nodeType === Node.TEXT_NODE && node.textContent.includes('·'));
        if (!code || !line) return;
        const parts = line.textContent.split('·').map((part) => part.trim()).filter(Boolean);
        if (parts.length < 3) return;
        line.remove();
        const heading = document.createElement('div');
        heading.className = 'staff-record-heading return-record-heading';
        heading.append(code,
            Object.assign(document.createElement('span'), {className: 'staff-record-description', textContent: parts.slice(1, -1).join(' · ')}),
            Object.assign(document.createElement('span'), {className: 'return-record-quantity', textContent: parts.at(-1)}),
            stateBadge(parts[0]));
        card.prepend(heading);
    });
    document.querySelectorAll('.warehouse-stocktake-row > div:first-child').forEach((body) => {
        const code = body.querySelector(':scope > strong');
        const line = [...body.childNodes].find((node) => node.nodeType === Node.TEXT_NODE && node.textContent.includes('·'));
        if (!code || !line) return;
        const parts = line.textContent.split('·').map((part) => part.trim()).filter(Boolean);
        if (parts.length < 2) return;
        line.remove();
        const heading = document.createElement('div');
        heading.className = 'staff-record-heading';
        heading.append(code, Object.assign(document.createElement('span'), {className: 'staff-record-description', textContent: parts.slice(1).join(' · ')}), stateBadge(parts[0]));
        body.prepend(heading);
    });
    document.querySelectorAll('#management-approvals article.order-card > form').forEach((form) => form.closest('article').append(form));

    const configs = [
        ['#contracts', 'details.order-card', 'Hợp đồng', ['Chờ phê duyệt', 'Bản nháp', 'Đã duyệt', 'Đã ký', 'Yêu cầu sửa', 'Đã hủy']],
        ['#finance tbody', 'tr', 'Công nợ', ['Phải thu', 'Phải trả', 'Dự kiến / chưa phát sinh', 'Chưa tất toán', 'Đã tất toán']],
        ['[aria-labelledby="debt-schedule-heading"]', 'details.order-card', 'Lịch quyết toán', ['Phải thu', 'Phải trả']],
        ['[aria-labelledby="customer-credits-heading"]', 'article.order-card', 'Khoản hoàn hoặc cấn trừ', ['Còn tiền', 'Đã dùng hết']],
        ['[aria-labelledby="supplier-return-heading"]', 'article.order-card', 'Bù trừ nhà cung cấp', ['Chưa bù trừ', 'Đã bù trừ']],
        ['#purchases', '.purchase-entry', 'Phiếu nhập', ['Chờ duyệt', 'Đã duyệt', 'Đã nhập kho']],
        ['#supplier-incidents', '.order-card', 'Sự vụ nhà cung cấp', ['Giao thiếu', 'Hàng hỏng', 'Đang xử lý', 'Đã xử lý']],
        ['#orders', '.order-card', 'Đơn và chuyến giao', ['Chờ xác nhận', 'Chờ lập hợp đồng', 'Chờ thanh toán', 'Đã giao', 'Đang giao', 'Sẵn sàng giao']],
        ['[aria-labelledby="delivery-runs-heading"]', 'article.order-card', 'Chuyến gom đơn', []],
        ['#returns', '.order-card', 'Yêu cầu đổi trả', ['Chờ xử lý', 'Đã thu hồi', 'Đã kiểm tra', 'Hoàn tất']],
        ['#inventory .warehouse-stock-panel:has(table) tbody', 'tr', 'Tồn kho', ['Dưới mức tối thiểu', 'Hết hàng']],
        ['#inventory .warehouse-stock-panel:has(.warehouse-stocktake-row)', '.warehouse-stocktake-row', 'Phiếu kiểm kê', ['Chờ duyệt', 'Đã duyệt', 'Từ chối']],
        ['#management-approvals', 'article.order-card', 'Đề xuất', ['Chờ duyệt', 'Đã duyệt', 'Đã từ chối']],
        ['#management-customers', 'form.order-card', 'Khách hàng', []],
        ['.staff-report-list', 'details.order-card', 'Báo cáo', ['Chờ duyệt', 'Đã duyệt', 'Cần đối soát', 'Tài chính', 'Tồn kho', 'Kinh doanh', 'Giao nhận']]
    ];

    configs.forEach((entry) => {
        let [selector, itemSelector, label, statuses] = entry;
        if (selector.startsWith('#inventory') && new URLSearchParams(location.search).get('view') === 'movements') {
            label = 'Lịch sử kho';
            statuses = ['Tồn đầu kỳ', 'Nhập kho', 'Xuất kho', 'Nhập hàng trả', 'Xuất hàng thay thế', 'Điều chỉnh kiểm kê'];
        }
        const root = document.querySelector(selector);
        if (!root) return;
        const items = [...root.querySelectorAll(itemSelector)].filter((item) => !item.closest('form.order-card') || item.matches('form.order-card'));
        if (!items.length) return;
        const target = root.matches('tbody') ? root.closest('.table-responsive') : root;
        const bar = document.createElement('div');
        bar.className = 'staff-filterbar';
        bar.setAttribute('role', 'search');
        bar.setAttribute('aria-label', `Lọc ${label.toLowerCase()}`);
        const searchLabel = document.createElement('label');
        searchLabel.textContent = `Tìm ${label.toLowerCase()}`;
        const search = document.createElement('input');
        search.className = 'form-control';
        search.type = 'search';
        search.placeholder = 'Nhập mã, tên hoặc nội dung';
        searchLabel.append(search);
        bar.append(searchLabel);
        let status;
        if (statuses.length) {
            const statusLabel = document.createElement('label');
            statusLabel.textContent = 'Trạng thái / loại';
            status = document.createElement('select');
            status.className = 'form-select';
            status.add(new Option('Tất cả', ''));
            statuses.forEach((value) => status.add(new Option(value, value)));
            statusLabel.append(status);
            bar.append(statusLabel);
        }
        const sortLabel = document.createElement('label');
        sortLabel.textContent = 'Sắp xếp';
        const sort = document.createElement('select');
        sort.className = 'form-select';
        sort.add(new Option('Mới nhất trước', 'new'));
        sort.add(new Option('Cũ nhất trước', 'old'));
        sortLabel.append(sort);
        bar.append(sortLabel);
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'btn staff-filter-clear';
        clear.textContent = 'Xóa lọc';
        bar.append(clear);
        const count = document.createElement('output');
        count.className = 'staff-filter-count';
        count.setAttribute('aria-live', 'polite');
        bar.append(count);
        if (target === root && !root.matches('.staff-report-list')) items[0].before(bar);
        else target.before(bar);
        const empty = document.createElement('p');
        empty.className = 'staff-filter-empty';
        empty.hidden = true;
        empty.textContent = 'Không có hồ sơ phù hợp. Hãy đổi điều kiện lọc.';
        target.after(empty);
        const original = items.map((item) => ({ item, parent: item.parentNode }));
        const apply = () => {
            const query = search.value.trim().toLocaleLowerCase('vi');
            const selected = status?.value || '';
            let visible = 0;
            original.forEach(({ item }) => {
                const text = item.textContent.toLocaleLowerCase('vi');
                const lowStock = selected === 'Dưới mức tối thiểu' && item.classList.contains('table-warning');
                const outOfStock = selected === 'Hết hàng' && [...item.querySelectorAll('td')].some((cell, index) => index === 2 && /^0(?:[.,]0+)?$/.test(cell.textContent.trim()));
                const balance = item.matches('tr') && selector === '#finance tbody' ? item.querySelectorAll('td')[5]?.textContent.trim() : null;
                const paidOff = balance !== null && /^0(?:[.,]0+)?\s*₫$/.test(balance);
                const matchStatus = !selected || (selected === 'Dưới mức tối thiểu' ? lowStock
                    : selected === 'Hết hàng' ? outOfStock
                    : selected === 'Chưa tất toán' ? balance !== null && !paidOff && !/dự kiến/i.test(item.querySelectorAll('td')[2]?.textContent || '')
                    : selected === 'Đã tất toán' ? paidOff
                    : selected === 'Còn tiền' ? !/còn\s+0\s*₫/i.test(text)
                    : selected === 'Đã dùng hết' ? /còn\s+0\s*₫/i.test(text)
                    : selected === 'Chưa bù trừ' ? !/đã bù trừ/i.test(text)
                    : text.includes(selected.toLocaleLowerCase('vi')));
                const show = text.includes(query) && matchStatus;
                item.hidden = !show;
                if (item._financeDetailRow) item._financeDetailRow.hidden = !show || !item.classList.contains('is-payment-open');
                if (show) visible++;
            });
            count.textContent = `${visible}/${items.length} hồ sơ`;
            empty.hidden = visible !== 0;
        };
        search.addEventListener('input', apply);
        status?.addEventListener('change', apply);
        sort.addEventListener('change', () => {
            const ordered = sort.value === 'old' ? [...original].reverse() : original;
            ordered.forEach(({ item, parent }) => { parent.append(item); if (item._financeDetailRow) parent.append(item._financeDetailRow); });
            apply();
        });
        clear.addEventListener('click', () => { search.value = ''; if (status) status.value = ''; sort.value = 'new'; sort.dispatchEvent(new Event('change')); search.focus(); });
        apply();
    });
    const columnHeading = (labels, className) => {
        const heading = document.createElement('div');
        heading.className = className;
        heading.setAttribute('aria-hidden', 'true');
        labels.forEach((label) => heading.append(Object.assign(document.createElement('span'), {textContent: label})));
        return heading;
    };
    document.querySelector('#contracts .staff-filterbar')?.after(columnHeading(['Mã hợp đồng', 'Đơn liên quan', 'Trạng thái', 'Giá trị'], 'contract-columns'));
    document.querySelector('#purchases .staff-filterbar')?.after(columnHeading(['Mã phiếu', 'Trạng thái', 'Giá trị', 'Thao tác'], 'purchase-columns'));
    document.querySelector('#orders .staff-filterbar')?.after(columnHeading(['Mã đơn', 'Trạng thái', 'Điểm giao / nhận', 'Chứng từ / thao tác'], 'orders-columns'));
    document.querySelector('#returns .staff-filterbar')?.after(columnHeading(['Mã phiếu', 'Nội dung yêu cầu', 'Số lượng', 'Trạng thái'], 'return-columns'));
    document.querySelector('main form[action*="reports"]')?.classList.add('staff-report-create');

    // Keep payment controls out of the ledger until the matching debt is opened.
    document.querySelectorAll('#finance tbody tr').forEach((row, index) => {
        const form = row.querySelector('td:last-child form');
        if (!form) return;
        const cell = row.cells[0];
        const code = cell.textContent.trim();
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'finance-row-toggle';
        toggle.textContent = code;
        toggle.setAttribute('aria-label', `Mở ghi nhận thanh toán cho ${code}`);
        toggle.setAttribute('aria-expanded', 'false');
        const panel = document.createElement('div');
        panel.id = `finance-payment-${index}`;
        panel.className = 'finance-payment-panel';
        panel.append(form);
        const detailRow = document.createElement('tr');
        detailRow.className = 'finance-payment-row';
        detailRow.hidden = true;
        const detailCell = document.createElement('td');
        detailCell.colSpan = row.cells.length;
        detailCell.append(panel);
        detailRow.append(detailCell);
        row.after(detailRow);
        row._financeDetailRow = detailRow;
        toggle.setAttribute('aria-controls', panel.id);
        cell.replaceChildren(toggle);
        const unsettled = document.createElement('span');
        unsettled.className = 'finance-note-badge is-unsettled';
        unsettled.textContent = 'Chưa tất toán';
        row.cells[6].replaceChildren(unsettled);
        const switchPayment = () => {
            detailRow.hidden = !detailRow.hidden;
            toggle.setAttribute('aria-expanded', String(!detailRow.hidden));
            row.classList.toggle('is-payment-open', !detailRow.hidden);
        };
        toggle.addEventListener('click', switchPayment);
        row.addEventListener('click', (event) => {
            if (event.target.closest('button, input, select, a, form')) return;
            switchPayment();
        });
    });

    const makeWorkspaceTabs = (name, entries) => {
        const available = entries.filter((entry) => entry.panel);
        if (available.length < 2) return;
        const nav = document.createElement('nav');
        nav.className = 'staff-work-tabs';
        nav.setAttribute('role', 'tablist');
        nav.setAttribute('aria-label', name);
        available[0].panel.before(nav);
        const storageKey = `staff-tab:${location.pathname}`;
        const selectTab = (index, focus = false) => {
            available.forEach(({panel}, i) => {
                panel.hidden = i !== index;
                const tab = nav.children[i];
                tab.setAttribute('aria-selected', String(i === index));
                tab.tabIndex = i === index ? 0 : -1;
                if (focus && i === index) tab.focus();
            });
            try { sessionStorage.setItem(storageKey, available[index].id); } catch (_) {}
        };
        available.forEach(({id, label, panel}, index) => {
            panel.id ||= `staff-tab-panel-${id}`;
            panel.setAttribute('role', 'tabpanel');
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.id = `staff-tab-${id}`;
            tab.className = 'staff-work-tab';
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panel.id);
            tab.textContent = label;
            if (!panel.hasAttribute('aria-labelledby')) panel.setAttribute('aria-labelledby', tab.id);
            tab.addEventListener('click', () => selectTab(index));
            tab.addEventListener('keydown', (event) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? available.length - 1
                    : (index + (event.key === 'ArrowRight' ? 1 : -1) + available.length) % available.length;
                selectTab(next, true);
            });
            nav.append(tab);
        });
        let saved;
        try { saved = sessionStorage.getItem(storageKey); } catch (_) {}
        selectTab(Math.max(0, available.findIndex((entry) => entry.id === saved)));
    };
    makeWorkspaceTabs('Tài chính và công nợ', [
        {id:'finance-ledger', label:'Công nợ', panel:document.querySelector('#finance')},
        {id:'finance-schedule', label:'Lịch quyết toán', panel:document.querySelector('[aria-labelledby="debt-schedule-heading"]')},
        {id:'finance-credits', label:'Hoàn và cấn trừ', panel:document.querySelector('[aria-labelledby="customer-credits-heading"]')},
        {id:'finance-supplier', label:'Bù trừ nhà cung cấp', panel:document.querySelector('[aria-labelledby="supplier-return-heading"]')},
    ]);
    makeWorkspaceTabs('Nhập hàng', [
        {id:'purchase-orders', label:'Đề xuất và phiếu nhập', panel:document.querySelector('#purchases')},
        {id:'purchase-incidents', label:'Sự cố hàng hóa', panel:document.querySelector('#supplier-incidents')},
    ]);
    const deliveryPanel = document.querySelector('[aria-labelledby="delivery-runs-heading"]');
    if (document.querySelector('#orders') && !deliveryPanel) {
        const empty = document.createElement('section');
        empty.className = 'staff-subpanel mb-5';
        empty.innerHTML = '<h2>Gom đơn giao cùng chuyến</h2><p>Chưa có chuyến phù hợp để gom đơn.</p>';
        document.querySelector('#orders').after(empty);
    }
    makeWorkspaceTabs('Xuất kho và giao nhận', [
        {id:'orders-fulfillment', label:'Đơn và giao nhận', panel:document.querySelector('#orders')},
        {id:'orders-runs', label:'Gom đơn cùng chuyến', panel:deliveryPanel || document.querySelector('#orders + .staff-subpanel')},
    ]);
    const management = [...document.querySelectorAll('main .staff-management-section')];
    makeWorkspaceTabs('Quản lý hệ thống', management.map((panel, index) => ({
        id:`management-${index}`,
        label:panel.querySelector(':scope > h2')?.textContent.trim() || `Mục ${index + 1}`,
        panel,
    })));
});
