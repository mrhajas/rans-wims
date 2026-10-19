/*
 * Places a hover/tap photo popup next to its thumbnail and keeps it fully on screen.
 *
 * Prefers the right of the thumbnail, flips to the left when there is no room, and on narrow screens
 * (neither side fits) centres the popup under — or above — the thumbnail. Every result is clamped to the
 * visible viewport with a small margin, so the popup can never be cut off at any edge.
 */
export function placeZoom(anchor, width, height, gap = 14, margin = 8) {
  const vw = document.documentElement.clientWidth || window.innerWidth;
  const vh = window.innerHeight;
  const w = Math.min(width, vw - margin * 2);
  const h = Math.min(height, vh - margin * 2);
  const clamp = (v, min, max) => Math.max(min, Math.min(v, max));
  const centreY = clamp(anchor.top + anchor.height / 2 - h / 2, margin, vh - h - margin);

  if (anchor.right + gap + w <= vw - margin) return { x: anchor.right + gap, y: centreY, w };
  if (anchor.left - gap - w >= margin) return { x: anchor.left - gap - w, y: centreY, w };

  const x = clamp(anchor.left + anchor.width / 2 - w / 2, margin, vw - w - margin);
  const below = anchor.bottom + gap;
  const y = below + h <= vh - margin ? below : clamp(anchor.top - gap - h, margin, vh - h - margin);

  return { x, y, w };
}
