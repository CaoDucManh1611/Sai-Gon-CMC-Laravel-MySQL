const main = document.querySelector('#main');
const galleryButtons = [...document.querySelectorAll('[data-gallery-view]')];
const gallerySlides = [...document.querySelectorAll('[data-gallery-panel]')];
galleryButtons.forEach(button => button.addEventListener('click', () => {
    const selected = Number(button.dataset.galleryView);
    gallerySlides.forEach((slide, index) => {
        slide.hidden = index !== selected;
        slide.classList.toggle('is-active', index === selected);
    });
    galleryButtons.forEach((item, index) => {
        item.classList.toggle('is-active', index === selected);
        item.setAttribute('aria-pressed', String(index === selected));
    });
    document.querySelector('[data-gallery-counter]').textContent = `0${selected + 1} / 0${gallerySlides.length}`;
}));
const cartScroll = sessionStorage.getItem('cartScrollY');
if (cartScroll !== null) {
    sessionStorage.removeItem('cartScrollY');
    window.scrollTo(0, Number(cartScroll));
}
const pendingCartUpdates = new Set();
let cartUpdateQueue = Promise.resolve();
let checkoutWaiting = false;
let cartActionSubmitted = false;
const cartActionButtons = [...document.querySelectorAll('[data-cart-submit] button[type="submit"]')];
const initialCartActionButtonStates = new Map(cartActionButtons.map(button => [button, button.disabled]));
const setCartControlsDisabled = disabled => {
    document.querySelectorAll('[data-cart-update-form]').forEach(form => {
        const input = form.querySelector('input[name="quantity"][type="number"]');
        const decrease = form.querySelector('button[value="decrease"]');
        const increase = form.querySelector('button[value="increase"]');
        const remove = form.querySelector('button[value="remove"]');
        if (input) input.disabled = disabled;
        if (decrease) decrease.disabled = disabled || Number(input.value) <= Number(input.min);
        if (increase) increase.disabled = disabled || Number(input.value) >= Number(input.max);
        if (remove) remove.disabled = disabled;
    });
};
window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    checkoutWaiting = false;
    cartActionSubmitted = false;
    setCartControlsDisabled(false);
    cartActionButtons.forEach(button => { button.disabled = initialCartActionButtonStates.get(button); });
});
document.querySelectorAll('[data-cart-update-form]').forEach(form => {
    const input = form.querySelector('input[name="quantity"][type="number"]');
    let submitting = false;
    form.addEventListener('submit', async event => {
        if (checkoutWaiting || cartActionSubmitted) {
            event.preventDefault();
            return;
        }
        if (window.fetch) {
            event.preventDefault();
            if (submitting || (input && !input.reportValidity())) return;
            submitting = true;
            const removing = !input;
            const actionButtonStates = cartActionButtons.map(button => button.disabled);
            if (removing) {
                checkoutWaiting = true;
                setCartControlsDisabled(true);
                cartActionButtons.forEach(button => { button.disabled = true; });
            }
            const data = new FormData(form);
            if (event.submitter?.name) data.set(event.submitter.name, event.submitter.value);
            const buttons = [...form.querySelectorAll('button')];
            const disabledBefore = buttons.map(button => button.disabled);
            buttons.forEach(button => { button.disabled = true; });
            if (input) input.disabled = true;
            const actions = form.closest('.cart-actions');
            let error = actions.querySelector('.cart-save-error');
            if (error) error.remove();
            const request = cartUpdateQueue.then(async () => {
                const response = await fetch(form.getAttribute('action'), {
                    method: 'POST', body: data, credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.errors?.quantity?.[0] || result.message || 'Không thể cập nhật số lượng.');
                const productId = form.querySelector('input[name="product_id"]').value;
                const total = document.querySelector('.summary-line.total strong');
                if (total) total.textContent = result.total;
                if (result.removed) {
                    form.closest('.cart-row').remove();
                    document.querySelectorAll(`[data-checkout-quantity="${productId}"]`).forEach(field => field.remove());
                    if (!document.querySelector('.cart-row')) {
                        const grid = document.querySelector('[data-cart-grid]');
                        const empty = document.createElement('div');
                        empty.className = 'empty-state mb-5';
                        const title = document.createElement('h2');
                        title.textContent = 'Giỏ hàng đang trống';
                        const catalogLink = document.createElement('a');
                        catalogLink.className = 'btn-editorial dark';
                        catalogLink.href = grid?.dataset.cartCatalogUrl || '/products';
                        catalogLink.textContent = 'Xem sản phẩm';
                        empty.append(title, catalogLink);
                        grid?.replaceWith(empty);
                    }
                } else {
                    input.value = result.quantity;
                    form.closest('.cart-row').querySelector('.cart-price').textContent = result.lineTotal;
                    document.querySelectorAll(`[data-checkout-quantity="${productId}"]`).forEach(field => { field.value = result.quantity; });
                    form.querySelector('button[value="decrease"]').disabled = !result.canDecrease;
                    form.querySelector('button[value="increase"]').disabled = Number(result.quantity) >= Number(input.max);
                }
                document.querySelectorAll('[data-cart-key]').forEach(field => { field.value = result.idempotencyKey; });
                return { ok: true, removed: result.removed };
            }).catch(cause => {
                error = document.createElement('span');
                error.className = 'cart-save-error';
                error.setAttribute('role', 'alert');
                error.textContent = cause.message || 'Không thể cập nhật số lượng.';
                actions.append(error);
                buttons.forEach((button, index) => { button.disabled = disabledBefore[index]; });
                return false;
            }).finally(() => {
                if (checkoutWaiting) setCartControlsDisabled(true);
                else if (input) input.disabled = false;
                if (removing) {
                    checkoutWaiting = false;
                    setCartControlsDisabled(false);
                    cartActionButtons.forEach((button, index) => { button.disabled = actionButtonStates[index]; });
                }
                submitting = false;
            });
            cartUpdateQueue = request;
            pendingCartUpdates.add(request);
            request.then(() => pendingCartUpdates.delete(request));
            return;
        }
        submitting = true;
        sessionStorage.setItem('cartScrollY', String(window.scrollY));
    });
    input?.addEventListener('change', () => {
        setTimeout(() => {
            if (document.activeElement?.closest('[data-cart-submit]')) return;
            if (!submitting && input.reportValidity()) form.requestSubmit();
        }, 0);
    });
});
document.querySelectorAll('[data-cart-submit]').forEach(form => {
    let waitingForCart = false;
    let resubmitting = false;
    const synchronizeQuantities = () => {
        const inputs = [...document.querySelectorAll('[data-cart-product-quantity]')];
        if (inputs.length === 0) return false;
        for (const input of inputs) {
            if (!input.reportValidity()) return false;
            const hidden = [...form.querySelectorAll('[data-checkout-quantity]')]
                .find(field => field.dataset.checkoutQuantity === input.dataset.cartProductQuantity);
            if (hidden) hidden.value = input.value;
        }

        return true;
    };

    form.addEventListener('submit', async event => {
        if (resubmitting) {
            resubmitting = false;
            return;
        }
        if (checkoutWaiting || cartActionSubmitted) {
            event.preventDefault();
            return;
        }
        if (waitingForCart) {
            event.preventDefault();
            return;
        }
        if (!synchronizeQuantities()) {
            event.preventDefault();
            document.querySelector('[data-cart-product-quantity]:invalid')?.focus();
            return;
        }
        const buttons = [...document.querySelectorAll('[data-cart-submit] button[type="submit"]')];
        const disabledBefore = buttons.map(button => button.disabled);
        cartActionSubmitted = true;
        if (pendingCartUpdates.size === 0) {
            buttons.forEach(button => { button.disabled = true; });
            return;
        }

        event.preventDefault();
        waitingForCart = true;
        checkoutWaiting = true;
        const submitter = event.submitter;
        setCartControlsDisabled(true);
        buttons.forEach(button => { button.disabled = true; });
        const results = await Promise.all([...pendingCartUpdates]);
        waitingForCart = false;
        checkoutWaiting = false;
        setCartControlsDisabled(false);
        if (results.some(result => !result) || !synchronizeQuantities() || !form.reportValidity()) {
            cartActionSubmitted = false;
            buttons.forEach((button, index) => { button.disabled = disabledBefore[index]; });
            return;
        }

        resubmitting = true;
        submitter ? form.requestSubmit(submitter) : form.requestSubmit();
    });
});
const header = document.querySelector('#siteHeader');
if (header) {
    const updateHeader = () => header.classList.toggle('is-scrolled', window.scrollY > 20);
    updateHeader();
    window.addEventListener('scroll', updateHeader, { passive: true });
}
if (main && 'IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const targets = main.querySelectorAll('.section-head,.category-card,.product-card,.benefit,.editorial-copy,.editorial-image,.page-intro,.detail-story h2,.story-image,.story-features>div,.order-card');
    const observer = new IntersectionObserver((entries, current) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                current.unobserve(entry.target);
            }
        });
    }, { threshold: .08, rootMargin: '0px 0px 35px 0px' });
    targets.forEach((element, index) => {
        element.classList.add('reveal-ready');
        element.style.setProperty('--reveal-delay', ((index % 4) * 70) + 'ms');
        observer.observe(element);
    });
}

const chatPanel = document.querySelector('#chatPanel');
const chatLauncher = document.querySelector('.chat-launcher');
const chatForm = document.querySelector('#chatForm');
if (chatPanel && chatLauncher && chatForm) {
    const question = chatForm.querySelector('textarea');
    const messages = document.querySelector('#chatMessages');
    const conversation = [];
    const transcript = [];
    const storageKey = 'cmc-chat-session-v1';
    const persistChat = () => {
        try {
            localStorage.setItem(storageKey, JSON.stringify({
                updatedAt: Date.now(), open: !chatPanel.hidden,
                conversation: conversation.slice(-6), transcript: transcript.slice(-30),
            }));
        } catch { /* Chat continues when storage is unavailable. */ }
    };
    const renderReply = (element, text) => {
        element.replaceChildren();
        const prices = /\d[\d.,]* (?:đồng|VNĐ|VND|đ)(?:\/[\p{L}\p{N}³²]+)?/gu;
        let position = 0;
        for (const match of text.matchAll(prices)) {
            element.append(document.createTextNode(text.slice(position, match.index)));
            const price = document.createElement('span');
            price.className = 'chat-price';
            price.textContent = match[0];
            element.append(price);
            position = match.index + match[0].length;
        }
        element.append(document.createTextNode(text.slice(position)));
    };
    const renderProducts = (products) => {
                const cards = document.createElement('div');
                cards.className = 'chat-products';
                for (const product of products.slice(0, 3)) {
                    if (!/^\/products\/\d+$/.test(product.url)) continue;
                    const card = document.createElement('article');
                    card.className = 'chat-product-card';
                    const photoLink = document.createElement('a');
                    photoLink.href = product.url;
                    photoLink.addEventListener('click', persistChat);
                    photoLink.rel = 'noopener';
                    photoLink.setAttribute('aria-label', 'Xem ' + product.name);
                    const image = document.createElement('span');
                    image.className = 'material-image' + (/^mat-[a-z0-9-]+$/.test(product.image_class) ? ' ' + product.image_class : '');
                    image.setAttribute('role', 'img');
                    image.setAttribute('aria-label', 'Hình minh họa ' + product.name);
                    photoLink.append(image);
                    const body = document.createElement('div');
                    body.className = 'chat-product-body';
                    const title = document.createElement('h3');
                    title.textContent = product.name;
                    const spec = document.createElement('p');
                    spec.textContent = product.specification || 'Liên hệ để biết quy cách';
                    const price = document.createElement('strong');
                    price.className = 'chat-price';
                    price.textContent = product.price;
                    const note = document.createElement('small');
                    note.textContent = 'Giá tham khảo · Hình minh họa';
                    const link = document.createElement('a');
                    link.href = product.url;
                    link.addEventListener('click', persistChat);
                    link.rel = 'noopener';
                    link.className = 'chat-product-link';
                    link.textContent = 'Xem chi tiết sản phẩm →';
                    body.append(title, spec, price, note, link);
                    card.append(photoLink, body);
                    cards.append(card);
                }
                if (cards.childElementCount) messages.append(cards);
    };
    const chatWidget = chatLauncher.closest('.chat-widget');
    const clamp = (value, size, viewport) => Math.min(Math.max(value, 12), Math.max(12, viewport - size - 12));
    const placeChat = (x, y) => {
        chatWidget.style.left = `${clamp(x, chatLauncher.offsetWidth, window.innerWidth)}px`;
        chatWidget.style.top = `${clamp(y, chatLauncher.offsetHeight, window.innerHeight)}px`;
        chatWidget.style.right = 'auto';
        chatWidget.style.bottom = 'auto';
    };
    const positionPanel = () => {
        const launcher = chatLauncher.getBoundingClientRect();
        const panel = chatPanel.getBoundingClientRect();
        const above = launcher.top - panel.height - 14;
        const below = launcher.bottom + 14;
        chatPanel.style.left = `${clamp(launcher.right - panel.width, panel.width, window.innerWidth)}px`;
        chatPanel.style.top = `${above >= 12 ? above : below + panel.height <= window.innerHeight - 12 ? below : clamp(above, panel.height, window.innerHeight)}px`;
    };
    try {
        const saved = JSON.parse(localStorage.getItem('cmcChatPosition') || 'null');
        if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) placeChat(saved.x, saved.y);
    } catch { /* Keep the default position when browser storage is unavailable. */ }
    const toggleChat = (open) => {
        chatPanel.hidden = !open;
        if (open) positionPanel();
        chatLauncher.setAttribute('aria-expanded', String(open));
        chatLauncher.setAttribute('aria-label', open ? 'Đóng trợ lý AI' : 'Mở trợ lý AI');
        persistChat();
        if (open) question.focus();
        else chatLauncher.focus();
    };
    const addMessage = (content, type) => {
        const message = document.createElement('p');
        message.className = `chat-message ${type}`;
        message.textContent = content;
        messages.append(message);
        messages.scrollTop = messages.scrollHeight;
        return message;
    };
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
        if (saved && Date.now() - saved.updatedAt < 24 * 60 * 60 * 1000 && Array.isArray(saved.transcript)) {
            for (const turn of saved.transcript.slice(-30)) {
                if (!['user', 'assistant'].includes(turn.role) || typeof turn.content !== 'string') continue;
                const content = turn.content.slice(0, 10000);
                const element = addMessage(content, turn.role);
                if (turn.role === 'assistant') renderReply(element, content);
                const products = Array.isArray(turn.products) ? turn.products.slice(0, 3).filter(product => product && typeof product.name === 'string' && typeof product.url === 'string') : [];
                if (products.length) renderProducts(products);
                transcript.push({ role: turn.role, content, products });
            }
            if (Array.isArray(saved.conversation)) {
                conversation.push(...saved.conversation.slice(-6).filter(turn => turn && ['user', 'assistant'].includes(turn.role) && typeof turn.content === 'string' && Array.from(turn.content).length <= 2000));
            }
            if (saved.open) {
                chatPanel.hidden = false;
                positionPanel();
                chatLauncher.setAttribute('aria-expanded', 'true');
                chatLauncher.setAttribute('aria-label', 'Đóng trợ lý AI');
            }
            messages.scrollTop = messages.scrollHeight;
        }
    } catch { /* Invalid stored data is ignored. */ }
    window.addEventListener('pagehide', persistChat);
    let dragStart = null;
    let suppressClick = false;
    chatLauncher.addEventListener('pointerdown', event => {
        if (!chatPanel.hidden || (event.pointerType === 'mouse' && event.button !== 0)) return;
        const rect = chatLauncher.getBoundingClientRect();
        dragStart = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, left: rect.left, top: rect.top, moved: false };
        chatLauncher.setPointerCapture(event.pointerId);
    });
    chatLauncher.addEventListener('pointermove', event => {
        if (!dragStart || event.pointerId !== dragStart.pointerId) return;
        const dx = event.clientX - dragStart.x;
        const dy = event.clientY - dragStart.y;
        if (!dragStart.moved && Math.hypot(dx, dy) < 6) return;
        dragStart.moved = true;
        chatWidget.classList.add('is-dragging');
        placeChat(dragStart.left + dx, dragStart.top + dy);
        event.preventDefault();
    });
    const finishDrag = event => {
        if (!dragStart || event.pointerId !== dragStart.pointerId) return;
        if (dragStart.moved) {
            suppressClick = true;
            setTimeout(() => { suppressClick = false; }, 300);
            try { localStorage.setItem('cmcChatPosition', JSON.stringify({ x: parseFloat(chatWidget.style.left), y: parseFloat(chatWidget.style.top) })); } catch { /* Position still works for this page. */ }
        }
        dragStart = null;
        chatWidget.classList.remove('is-dragging');
    };
    chatLauncher.addEventListener('pointerup', finishDrag);
    chatLauncher.addEventListener('pointercancel', finishDrag);
    chatLauncher.addEventListener('click', event => {
        if (suppressClick) { event.preventDefault(); return; }
        toggleChat(chatPanel.hidden);
    });
    window.addEventListener('resize', () => {
        if (chatWidget.style.left) placeChat(parseFloat(chatWidget.style.left), parseFloat(chatWidget.style.top));
        if (!chatPanel.hidden) positionPanel();
    });
    chatPanel.querySelector('[data-chat-close]').addEventListener('click', () => toggleChat(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !chatPanel.hidden) toggleChat(false);
    });
    question.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            chatForm.requestSubmit();
        }
    });
    chatForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!chatForm.reportValidity()) return;
        const prompt = question.value.trim();
        if (prompt.length < 3) return;
        const button = chatForm.querySelector('button[type="submit"]');
        if (button.disabled) return;
        addMessage(prompt, 'user');
        transcript.push({ role: 'user', content: prompt });
        question.value = '';
        button.disabled = true;
        const pending = addMessage('Mình đang xem thông tin…', 'assistant is-pending');
        try {
            const data = new FormData(chatForm);
            data.set('question', prompt);
            conversation.slice(-6).forEach((turn, index) => {
                data.set(`history[${index}][role]`, turn.role);
                data.set(`history[${index}][content]`, turn.content);
            });
            const response = await fetch(chatForm.action, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: data,
            });
            const result = await response.json();
            if (response.ok && typeof result.reply === 'string') renderReply(pending, result.reply);
            else pending.textContent = result.message || 'Không gửi được câu hỏi. Vui lòng thử lại.';
            if (response.ok && typeof result.reply === 'string') {
                const productNames = Array.isArray(result.products) ? result.products.map(product => product.name).join(', ') : '';
                conversation.push({ role: 'user', content: prompt }, { role: 'assistant', content: Array.from(result.reply + (productNames ? ' Sản phẩm: ' + productNames : '')).slice(0, 2000).join('') });
                if (conversation.length > 6) conversation.splice(0, conversation.length - 6);
            }
            transcript.push({ role: 'assistant', content: pending.textContent, products: response.ok && Array.isArray(result.products) ? result.products.slice(0, 3) : [] });
            if (response.ok && Array.isArray(result.products)) {
                renderProducts(result.products);
            }
        } catch {
            pending.textContent = 'Không kết nối được trợ lý. Vui lòng thử lại.';
        } finally {
            pending.classList.remove('is-pending');
            persistChat();
            button.disabled = false;
            messages.scrollTop = messages.scrollHeight;
        }
    });
}
