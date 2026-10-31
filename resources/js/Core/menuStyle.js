// The look of the main menu (Classic or Pro) is the person's own choice. It changes at once and is kept on their own settings.
export function pageAllowsPro(page, can) {
  return page.props.enabledFeatures?.pro_menu !== false && can(['use-pro-menu']);
}

export function applyMenuStyle(page, style) {
  const u = page.props.auth.user;
  u.extra_attributes = { ...(u.extra_attributes || {}), menu_style: style };
  return window.axios.put(route('menu.style.update'), { style }, { headers: { 'X-Quiet': '1' } }).catch(() => {});
}
