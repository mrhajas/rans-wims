// The sign-in and error pages cannot know who is about to sign in, so they follow the last menu style used on THIS device: when it was Glass the screens are glass too.
// AdminLayout keeps this note up to date (on while the Glass menu is in use, off for Classic and Pro).
const KEY = 'wims_glass_ui';

export function glassUiOn() {
  try { return window.localStorage.getItem(KEY) === '1'; } catch (e) { return false; }
}

export function setGlassUi(on) {
  try { if (on) window.localStorage.setItem(KEY, '1'); else window.localStorage.removeItem(KEY); } catch (e) { /* no storage */ }
}
