// The look of the main menu (Classic, Pro or Glass) is the person's own choice. It changes at once and is kept on their own settings.
// Pro and Glass are both allowed by the same Global Feature Control switch (pro_menu) and permission (use-pro-menu).
export function pageAllowsPro(page, can) {
  return page.props.enabledFeatures?.pro_menu !== false && can(['use-pro-menu']);
}

// the style the person actually gets: their choice, if they may use it, otherwise Classic
export function menuStyleOf(page, can) {
  const s = page.props.auth?.user?.extra_attributes?.menu_style;

  return (s === 'pro' || s === 'glass') && pageAllowsPro(page, can) ? s : 'classic';
}

export function applyMenuStyle(page, style) {
  const u = page.props.auth.user;
  u.extra_attributes = { ...(u.extra_attributes || {}), menu_style: style };
  return window.axios.put(route('menu.style.update'), { style }, { headers: { 'X-Quiet': '1' } }).catch(() => {});
}
