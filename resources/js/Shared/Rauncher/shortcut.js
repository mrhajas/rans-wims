export const DEFAULT_SHORTCUT = { ctrl: true, meta: true, shift: false, alt: false, key: 'h' };

// Also used by the barcode-scan shortcut (Profile > Scan Shortcut, and each
// transaction form's camera scanner) — kept here since the helpers below are
// generic, not Rauncher-specific.
export const DEFAULT_SCAN_SHORTCUT = { ctrl: true, meta: false, shift: false, alt: true, key: 's' };

export function shortcutFromEvent(e) {
  return {
    ctrl: e.ctrlKey,
    meta: e.metaKey,
    shift: e.shiftKey,
    alt: e.altKey,
    key: e.key.length === 1 ? e.key.toLowerCase() : e.key,
  };
}

export function matchesShortcut(e, shortcut) {
  if (!shortcut || !shortcut.key) return false;
  return (
    e.ctrlKey === !!shortcut.ctrl &&
    e.metaKey === !!shortcut.meta &&
    e.shiftKey === !!shortcut.shift &&
    e.altKey === !!shortcut.alt &&
    e.key.toLowerCase() === String(shortcut.key).toLowerCase()
  );
}

export function formatShortcut(shortcut) {
  if (!shortcut) return '';
  const parts = [];
  if (shortcut.ctrl) parts.push('Ctrl');
  if (shortcut.meta) parts.push('⌘/Win');
  if (shortcut.alt) parts.push('Alt');
  if (shortcut.shift) parts.push('Shift');
  parts.push(String(shortcut.key || '').toUpperCase());
  return parts.join(' + ');
}
