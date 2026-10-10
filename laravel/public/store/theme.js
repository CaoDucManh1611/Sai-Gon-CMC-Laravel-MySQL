(() => {
  const root = document.documentElement;
  const buttons = document.querySelectorAll('[data-theme-toggle]');
  const sync = () => {
    const dark = root.dataset.theme === 'dark';
    const label = dark ? 'Bật giao diện sáng' : 'Bật giao diện tối';
    buttons.forEach(button => {
      button.setAttribute('aria-label', label);
      button.setAttribute('aria-pressed', String(dark));
      button.title = label;
    });
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#101a17' : '#f6f4ef');
  };
  sync();
  buttons.forEach(button => button.addEventListener('click', () => {
    root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    root.dataset.bsTheme = root.dataset.theme;
    try { localStorage.setItem('cmc-theme', root.dataset.theme); } catch { /* Storage may be unavailable. */ }
    sync();
  }));
})();
