let activeSelect = null;

const dismissNotice = notice => {
    if (notice.classList.contains('is-leaving')) return;
    notice.classList.add('is-leaving');
    window.setTimeout(() => notice.remove(), 300);
};
document.querySelectorAll('[data-notice-close]').forEach(button => {
    button.addEventListener('click', () => dismissNotice(button.closest('.site-notice')));
});
document.querySelectorAll('.site-notice').forEach(notice => {
    window.setTimeout(() => dismissNotice(notice), 4700);
});
document.querySelectorAll('textarea[data-validation-message]').forEach(field => {
    field.addEventListener('invalid', () => field.setCustomValidity(field.dataset.validationMessage));
    field.addEventListener('input', () => field.setCustomValidity(''));
});

document.querySelectorAll('select:not([multiple]):not([size])').forEach((select, number) => {
    if (select.disabled) return;

    const wrapper = document.createElement('span');
    wrapper.className = 'cmc-select';
    wrapper.style.width = select.style.width || (select.classList.contains('form-select') && !select.classList.contains('d-inline-block') ? '100%' : '170px');
    select.before(wrapper);
    wrapper.append(select);
    select.classList.add('cmc-native-select');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'cmc-select-button';
    button.setAttribute('role', 'combobox');
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', select.labels?.[0]?.textContent?.trim() || select.getAttribute('aria-label') || select.name || 'Chọn giá trị');
    const value = document.createElement('span');
    const arrow = document.createElement('span');
    arrow.className = 'cmc-select-arrow';
    arrow.setAttribute('aria-hidden', 'true');
    button.append(value, arrow);
    wrapper.append(button);
    const validationMessage = select.dataset.validationMessage;
    const error = validationMessage ? document.createElement('p') : null;
    if (error) {
        error.className = 'cmc-select-error';
        error.id = `cmc-select-error-${number}`;
        error.setAttribute('role', 'alert');
        error.hidden = true;
        error.textContent = validationMessage;
        wrapper.after(error);
    }

    const menu = document.createElement('div');
    menu.className = 'cmc-select-menu';
    menu.id = `cmc-select-menu-${number}`;
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    button.setAttribute('aria-controls', menu.id);
    document.body.append(menu);

    const options = [...select.options].map((option, index) => {
        const item = document.createElement('div');
        item.id = `${menu.id}-option-${index}`;
        item.className = 'cmc-select-option';
        item.setAttribute('role', 'option');
        item.textContent = option.textContent;
        item.dataset.index = String(index);
        if (option.disabled) item.setAttribute('aria-disabled', 'true');
        if (option.hidden) item.hidden = true;
        menu.append(item);
        return item;
    });
    let highlighted = select.selectedIndex;

    const sync = () => {
        value.textContent = select.selectedOptions[0]?.textContent || 'Chọn giá trị';
        options.forEach((item, index) => item.setAttribute('aria-selected', String(index === select.selectedIndex)));
        wrapper.classList.remove('is-invalid');
        if (error) {
            error.hidden = true;
            button.removeAttribute('aria-describedby');
        }
    };
    const highlight = index => {
        if (!options[index] || select.options[index].disabled) return;
        highlighted = index;
        options.forEach((item, position) => item.classList.toggle('is-highlighted', position === index));
        button.setAttribute('aria-activedescendant', options[index].id);
        options[index].scrollIntoView({ block: 'nearest' });
    };
    const position = () => {
        const rect = button.getBoundingClientRect();
        const height = Math.min(menu.scrollHeight, 280);
        menu.style.left = `${Math.max(8, rect.left)}px`;
        menu.style.width = `${rect.width}px`;
        menu.style.top = `${rect.bottom + height + 8 > window.innerHeight && rect.top > height ? rect.top - height - 6 : rect.bottom + 6}px`;
    };
    const close = () => {
        menu.hidden = true;
        wrapper.classList.remove('is-open');
        button.setAttribute('aria-expanded', 'false');
        button.removeAttribute('aria-activedescendant');
        if (activeSelect?.button === button) activeSelect = null;
    };
    const open = () => {
        activeSelect?.close();
        menu.hidden = false;
        wrapper.classList.add('is-open');
        button.setAttribute('aria-expanded', 'true');
        activeSelect = { button, menu, wrapper, close, position };
        position();
        highlight(Math.max(0, select.selectedIndex));
    };
    const choose = index => {
        if (select.options[index].disabled) return;
        select.selectedIndex = index;
        sync();
        close();
        button.focus();
        select.dispatchEvent(new Event('change', { bubbles: true }));
    };

    button.addEventListener('click', () => menu.hidden ? open() : close());
    button.addEventListener('keydown', event => {
        if (event.key === 'Escape') { close(); return; }
        if (event.key === 'Tab') { close(); return; }
        if (['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', ' '].includes(event.key)) event.preventDefault();
        if (menu.hidden && ['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) { open(); return; }
        if (menu.hidden) return;
        if (event.key === 'Enter' || event.key === ' ') choose(highlighted);
        if (event.key === 'Home') highlight(options.findIndex((_, index) => !select.options[index].disabled));
        if (event.key === 'End') highlight(select.options.findLastIndex(option => !option.disabled));
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            let next = highlighted + direction;
            while (next >= 0 && next < options.length && select.options[next].disabled) next += direction;
            if (next >= 0 && next < options.length) highlight(next);
        }
    });
    menu.addEventListener('click', event => {
        const item = event.target.closest('[data-index]');
        if (item) choose(Number(item.dataset.index));
    });
    select.addEventListener('change', sync);
    select.addEventListener('invalid', event => {
        event.preventDefault();
        wrapper.classList.add('is-invalid');
        if (error) {
            error.hidden = false;
            button.setAttribute('aria-describedby', error.id);
        }
        button.focus();
    });
    sync();
});

document.addEventListener('pointerdown', event => {
    if (activeSelect && !activeSelect.wrapper.contains(event.target) && !activeSelect.menu.contains(event.target)) activeSelect.close();
});
window.addEventListener('resize', () => activeSelect?.position());
window.addEventListener('scroll', () => activeSelect?.position(), true);

document.querySelectorAll('[data-product-stepper]').forEach(stepper => {
    const input = stepper.querySelector('input');
    const decrease = stepper.querySelector('[data-step="down"]');
    const increase = stepper.querySelector('[data-step="up"]');
    const sync = () => {
        decrease.disabled = Number(input.value) <= Number(input.min);
        increase.disabled = Number(input.value) >= Number(input.max);
    };
    stepper.addEventListener('click', event => {
        const button = event.target.closest('[data-step]');
        if (!button || button.disabled) return;
        if (button.dataset.step === 'up') input.stepUp();
        else input.stepDown();
        sync();
    });
    input.addEventListener('input', () => {
        input.setCustomValidity('');
        sync();
    });
    input.addEventListener('invalid', () => {
        if (input.validity.valueMissing || input.validity.badInput) input.setCustomValidity('Vui lòng nhập số lượng.');
        else if (input.validity.rangeUnderflow) input.setCustomValidity(`Số lượng phải lớn hơn hoặc bằng ${input.min}.`);
        else if (input.validity.rangeOverflow) input.setCustomValidity(`Số lượng không được vượt quá ${input.max}.`);
        else if (input.validity.stepMismatch) input.setCustomValidity(input.step === '1' ? 'Số lượng phải là số nguyên.' : 'Số lượng chỉ được có tối đa 3 chữ số thập phân.');
    });
    sync();
});
