/**
 * After the server rejects a form (Inertia validation error), bring the first invalid field into view and focus it.
 * The red glow itself is pure CSS (see `.wims-field-error` rules in app.css), driven by the same error message each
 * field already shows, so the two can never disagree.
 */
const FIELD = 'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([disabled]):not([readonly]), select:not([disabled]), textarea:not([disabled]):not([readonly])';

const visible = el => !!el && el.offsetParent !== null && el.style.display !== 'none';

const firstInvalid = () => {
  const messages = Array.from(document.querySelectorAll('.wims-field-error')).filter(m => visible(m) && m.textContent.trim() !== '');
  const msg = messages[0];
  if (!msg) return null;

  // Prefer a control that shares the message's wrapper; otherwise scroll to the message itself.
  let wrap = msg.parentElement;
  for (let i = 0; wrap && i < 3; i++, wrap = wrap.parentElement) {
    const control = Array.from(wrap.querySelectorAll(FIELD)).find(visible);
    if (control) return { target: control, focusable: true };
  }

  return { target: msg, focusable: false };
};

export const initFieldErrors = router => {
  router.on('error', () => {
    // Wait for Vue to render the new error messages.
    window.setTimeout(() => {
      const hit = firstInvalid();
      if (!hit) return;

      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      hit.target.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
      if (hit.focusable) {
        try { hit.target.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
      }
    }, 80);
  });
};
