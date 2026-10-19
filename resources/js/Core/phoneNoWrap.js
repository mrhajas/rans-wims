/*
 * Phones only: reference codes (AST-000211, CMP-000011, RANS/2026/DEMOA/00005 ...) and short chips ("Pending Match",
 * "Under Revision") must stay on one line. CSS cannot tell a code from a name, so this marks them with `.nw-code`
 * (white-space: nowrap, see responsive.css). It runs once the page has settled after each change, never while scrolling,
 * and does nothing at tablet / desktop widths.
 */
if (typeof window !== 'undefined' && typeof document !== 'undefined' && window.matchMedia) {
  const phone = window.matchMedia('(max-width: 767.98px)');
  const CODE = /^[A-Za-z0-9]{2,}(?:[-\/][A-Za-z0-9.]+){1,6}$/;
  let timer = null;

  const mark = () => {
    timer = null;
    if (!phone.matches) return;
    const root = document.querySelector('.app-canvas');
    if (!root) return;
    root.querySelectorAll('td, b, strong, small, span, a, div, h3, h4, p, li').forEach(el => {
      if (el.children.length || el.classList.contains('nw-code')) return;
      const t = (el.textContent || '').trim();
      if (t.length < 5 || t.length > 40) return;
      if (CODE.test(t) || (el.classList.contains('lg-chip') && t.length <= 22)) el.classList.add('nw-code');
    });
  };

  const schedule = () => {
    if (timer) return;
    timer = setTimeout(() => (window.requestIdleCallback ? window.requestIdleCallback(mark, { timeout: 800 }) : mark()), 350);
  };

  const start = () => {
    schedule();
    const root = document.getElementById('app') || document.body;
    new MutationObserver(schedule).observe(root, { childList: true, subtree: true });
    document.addEventListener('inertia:navigate', schedule);
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}
