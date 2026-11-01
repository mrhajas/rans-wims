// The look of the main menu (Classic, Pro or Glass) is the person's own choice, kept on their own settings.
// Pro and Glass are both allowed by the same Global Feature Control switch (pro_menu) and permission (use-pro-menu).
export function pageAllowsPro(page, can) {
  return page.props.enabledFeatures?.pro_menu !== false && can(['use-pro-menu']);
}

// the style the person actually gets: their choice, if they may use it, otherwise Classic
export function menuStyleOf(page, can) {
  const s = page.props.auth?.user?.extra_attributes?.menu_style;

  return (s === 'pro' || s === 'glass') && pageAllowsPro(page, can) ? s : 'classic';
}

// The choice is saved, then the whole page reloads once (one refresh, nothing else): the new menu is drawn from the saved choice with its own opening animation
// (html.menu-boot, see glass.css), exactly as after pressing refresh.
export function applyMenuStyle(page, style) {
  // any open dropdown (the profile menu, the language list ...) is closed first
  document.dispatchEvent(new KeyboardEvent('keydown', { keyCode: 27 }));

  return window.axios.put(route('menu.style.update'), { style }, { headers: { 'X-Quiet': '1' } })
    .catch(() => {})
    .finally(() => setTimeout(() => window.location.reload(), 450)); // a moment for the slider to glide to the choice
}
