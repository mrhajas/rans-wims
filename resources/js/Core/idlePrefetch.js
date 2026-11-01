// Parts of the page that are only needed when someone opens them (the chat window, a dialog) are not part of the first download: they are fetched when the
// browser has nothing else to do, a few seconds after the page is ready, so they are already on the device (and in the offline cache) when they are opened.
// Nothing is fetched on a "data saver" connection; the part is then fetched when it is opened.
const done = new Set();

export function prefetchLater(loader, delay = 5000) {
  if (typeof window === 'undefined' || done.has(loader)) return;
  done.add(loader);
  const c = navigator.connection;
  if (c && (c.saveData || /(^|-)2g$/.test(c.effectiveType || ''))) return;
  const run = () => { try { loader().catch(() => {}); } catch (e) { /* fetched when opened */ } };
  const idle = () => ('requestIdleCallback' in window ? window.requestIdleCallback(run, { timeout: 20000 }) : setTimeout(run, 1000));
  if (document.readyState === 'complete') setTimeout(idle, delay);
  else window.addEventListener('load', () => setTimeout(idle, delay), { once: true });
}
