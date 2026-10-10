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
document.querySelectorAll('[data-cart-update-form]').forEach(form => {
    const input = form.querySelector('input[name="quantity"][type="number"]');
    let submitting = false;
    form.addEventListener('submit', () => {
        submitting = true;
        sessionStorage.setItem('cartScrollY', String(window.scrollY));
    });
    input?.addEventListener('change', () => {
        setTimeout(() => {
            if (!submitting && input.reportValidity()) form.requestSubmit();
        }, 0);
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
        addMessage(prompt, 'user');
        question.value = '';
        button.disabled = true;
        const pending = addMessage('Đang tìm câu trả lời…', 'assistant');
        try {
            const data = new FormData(chatForm);
            data.set('question', prompt);
            const response = await fetch(chatForm.action, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: data,
            });
            const result = await response.json();
            pending.textContent = response.ok ? result.reply : (result.message || 'Không gửi được câu hỏi. Vui lòng thử lại.');
        } catch {
            pending.textContent = 'Không kết nối được trợ lý. Vui lòng thử lại.';
        } finally {
            button.disabled = false;
            messages.scrollTop = messages.scrollHeight;
        }
    });
}
