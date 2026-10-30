// When an item is added to a list the form scrolls a little, just enough for the new last row to be seen with some room below it
// (never when it is already in view with that room, never when many rows arrive at once, as in an import).
export function revealLast(selector, room = 150) {
  const look = () => {
    const rows = [...document.querySelectorAll(selector)].filter(el => el.getClientRects().length);
    const last = rows[rows.length - 1];
    if (!last) return;
    last.style.scrollMarginBottom = room + 'px';
    const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    last.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: calm ? 'auto' : 'smooth' });
  };
  // after Vue has drawn the row (a timer, not a frame: a background tab does not run frames)
  setTimeout(look, 60);
}

// the watcher of a list: reveal only when exactly one row was added
export function onRowAdded(selector, room) {
  return function (now, before) {
    if (now === before + 1) revealLast(selector, room);
  };
}
