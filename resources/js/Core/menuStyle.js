import { router } from '@inertiajs/vue3';

// The look of the main menu (Classic, Pro or Glass) is the person's own choice. It changes at once, without reloading, and is kept on their own settings.
// Pro and Glass are both allowed by the same Global Feature Control switch (pro_menu) and permission (use-pro-menu).
export function pageAllowsPro(page, can) {
  return page.props.enabledFeatures?.pro_menu !== false && can(['use-pro-menu']);
}

// the style the person actually gets: their choice, if they may use it, otherwise Classic
export function menuStyleOf(page, can) {
  const s = page.props.auth?.user?.extra_attributes?.menu_style;

  return (s === 'pro' || s === 'glass') && pageAllowsPro(page, can) ? s : 'classic';
}

// A safety net after the menu has changed: if a leftover full-screen layer (an open dropdown's click catcher, a backdrop) ends up above the new menu, it is made
// transparent to the pointer, so the first click always reaches the menu (the layer itself is only a click catcher; it is not removed from the page).
function freeTheMenu() {
  const dock = document.querySelector('.pro-dock-fixed, .liquid-full-sidebar');
  if (!dock) return;
  const z = parseFloat(getComputedStyle(document.documentElement).zoom) || 1;
  [...dock.querySelectorAll('a, button')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 8 && r.height > 8 && r.top >= 0 && r.top < window.innerHeight - 80 && r.left >= 0 && r.left < 400; }).slice(0, 4).forEach(e => {
    const r = e.getBoundingClientRect();
    const h = document.elementFromPoint((r.left + r.width / 2) * z, (r.top + r.height / 2) * z);
    if (!h || dock.contains(h) || h.closest('.gp, .gm-tip, .pm-tip, [role="dialog"], [role="menu"]')) return;
    const cs = getComputedStyle(h);
    const big = h.getBoundingClientRect();
    if (cs.position === 'fixed' && big.width * z >= window.innerWidth * z * 0.6 && big.height * z >= window.innerHeight * z * 0.6) h.style.pointerEvents = 'none';
  });
}

// After the menu has changed the page is fetched again in place (the same address, scroll kept, no browser reload): everything on screen is then built
// fresh with the saved choice, exactly as after a reload, so the new menu and the page work from the first click. A page where something is being typed is left alone.
function settlePage() {
  try {
    if ([...document.querySelectorAll('[role="dialog"]')].some(d => d.getClientRects().length)) return;
    const typing = [...document.querySelectorAll('[scroll-region] input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=search]), [scroll-region] textarea')].some(e => String(e.value || '').trim() !== '');
    if (typing) return;
    router.visit(window.location.pathname + window.location.search, { replace: true, preserveScroll: true, preserveState: false, headers: { 'X-Quiet': '1' } });
  } catch (e) { /* the menu already works; nothing more to do */ }
}

// The choice is saved at once. On screen the menu in use slides out to the left and the new one slides in (html.menu-out, then html.menu-in: see glass.css);
// with "reduce motion" it simply changes.
export function applyMenuStyle(page, style) {
  const u = page.props.auth.user;
  const save = window.axios.put(route('menu.style.update'), { style }, { headers: { 'X-Quiet': '1' } }).catch(() => {});
  const swap = () => { u.extra_attributes = { ...(u.extra_attributes || {}), menu_style: style }; };
  const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const root = document.documentElement;
  // any open dropdown (the profile menu, the language list ...) is closed first: its full-screen layer would sit over the new menu and take the first click
  // (the Dropdown component closes on key code 27; the Glass profile drawer listens for key "Escape" only, so it stays open on purpose)
  document.dispatchEvent(new KeyboardEvent('keydown', { keyCode: 27 }));
  if (calm || root.classList.contains('menu-out')) { swap(); setTimeout(freeTheMenu, 80); save.finally(() => setTimeout(settlePage, 300)); return save; }

  root.classList.add('menu-out');

  return new Promise(resolve => {
    setTimeout(() => {
      try {
        swap();
      } finally {
        // whatever happens, the motion classes never stay on the page (they would hide or block the menu)
        root.classList.remove('menu-out');
        root.classList.add('menu-in');
        setTimeout(() => root.classList.remove('menu-in'), 900);
        setTimeout(freeTheMenu, 80);
        setTimeout(freeTheMenu, 1000);
        save.finally(() => setTimeout(settlePage, 900));
      }
      save.then(resolve, resolve);
    }, 290);
  });
}
