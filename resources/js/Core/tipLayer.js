/*
 * One floating tooltip for every `.lg-tip[data-tip]` element.
 *
 * Pure-CSS `::after` bubbles get clipped by table wrappers / cards (overflow) and by the window edge. This layer
 * draws the bubble as a single fixed element on top of everything (z-index above dropdowns and modals), puts it
 * ABOVE the control when there is room (BELOW when the control is at the very top, e.g. the top bar), and keeps it
 * inside the screen on narrow phones. Positions use getBoundingClientRect(), which zoomCompat.js normalises.
 */
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  let pop = null;
  let current = null;
  let timer = null;

  const ensure = () => {
    if (pop) return pop;
    pop = document.createElement('div');
    pop.className = 'lg-tip-pop';
    pop.setAttribute('role', 'tooltip');
    pop.innerHTML = '<span class="lg-tip-pop-text"></span><i class="lg-tip-pop-arrow"></i>';
    document.body.appendChild(pop);
    return pop;
  };

  const hide = () => {
    clearTimeout(timer);
    current = null;
    if (pop) pop.classList.remove('is-on');
  };

  const show = el => {
    const text = el.getAttribute('data-tip');
    if (!text) return;
    current = el;
    const p = ensure();
    p.querySelector('.lg-tip-pop-text').textContent = text;
    p.style.left = '0px';
    p.style.top = '0px';
    p.classList.remove('is-below');
    p.classList.add('is-on');

    const r = el.getBoundingClientRect();
    const vw = document.documentElement.clientWidth || window.innerWidth;
    const pw = p.offsetWidth;
    const ph = p.offsetHeight;
    const gap = 10;
    let top = r.top - ph - gap;
    let below = false;
    if (top < 6) {
      top = r.bottom + gap;
      below = true;
    }
    let left = r.left + r.width / 2 - pw / 2;
    left = Math.max(8, Math.min(left, vw - pw - 8));
    p.style.left = Math.round(left) + 'px';
    p.style.top = Math.round(top) + 'px';
    p.classList.toggle('is-below', below);
    const arrow = p.querySelector('.lg-tip-pop-arrow');
    const ax = Math.max(12, Math.min(r.left + r.width / 2 - left, pw - 12));
    arrow.style.left = Math.round(ax) + 'px';
  };

  const target = e => (e.target && e.target.closest ? e.target.closest('.lg-tip[data-tip]') : null);

  document.addEventListener('mouseover', e => {
    // the control the bubble belongs to was re-drawn or removed, or the pointer is somewhere else now: the bubble goes at once
    if (current && (!current.isConnected || !current.contains(e.target))) hide();
    const el = target(e);
    if (el && el !== current) show(el);
  });
  document.addEventListener('mouseout', e => {
    const el = target(e);
    if (el && (!e.relatedTarget || !el.contains(e.relatedTarget))) hide();
  });
  document.addEventListener('focusin', e => {
    const el = target(e);
    if (el) show(el);
  });
  document.addEventListener('focusout', hide);
  document.addEventListener('mousedown', hide, true);
  window.addEventListener('scroll', hide, true);
  window.addEventListener('resize', hide);
  document.addEventListener(
    'touchstart',
    e => {
      const el = target(e);
      if (el) {
        show(el);
        clearTimeout(timer);
        timer = setTimeout(hide, 1800);
      }
    },
    { passive: true }
  );
}
