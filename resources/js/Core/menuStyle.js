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

// The choice is saved at once. On screen the menu in use slides out to the left and the new one slides in (html.menu-out, then html.menu-in: see glass.css);
// with "reduce motion" it simply changes.
export function applyMenuStyle(page, style) {
  const u = page.props.auth.user;
  const save = window.axios.put(route('menu.style.update'), { style }, { headers: { 'X-Quiet': '1' } }).catch(() => {});
  const swap = () => { u.extra_attributes = { ...(u.extra_attributes || {}), menu_style: style }; };
  const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const root = document.documentElement;
  if (calm || root.classList.contains('menu-out')) { swap(); return save; }

  root.classList.add('menu-out');

  return new Promise(resolve => {
    setTimeout(() => {
      swap();
      root.classList.remove('menu-out');
      root.classList.add('menu-in');
      setTimeout(() => root.classList.remove('menu-in'), 900);
      save.then(resolve, resolve);
    }, 290);
  });
}
