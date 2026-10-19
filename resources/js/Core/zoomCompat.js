/*
 * Keeps JS positioning correct under the compact desktop scale (`html { zoom: var(--wims-scale) }` in app.css).
 *
 * With CSS zoom the browser reports element rectangles in on-screen pixels, while `position: fixed; left/top`
 * values are interpreted in the zoomed page's own (CSS) pixels - so every popup positioned from
 * getBoundingClientRect() (dropdowns, tooltips, date pickers) would land 10% off. Here rectangles and the
 * viewport size are reported in the same CSS pixel space the page lays out in, so that code keeps working
 * unchanged. Chart and map containers manage their own pointer maths against the real on-screen rectangle,
 * so they are left untouched.
 */
let factor = 1;

function readFactor() {
  const z = parseFloat(getComputedStyle(document.documentElement).zoom);
  factor = z > 0 && z < 1.001 ? z : 1;
}

function isNativeMeasured(el) {
  const first = el.firstElementChild;
  return (
    el.classList.contains('highcharts-container') ||
    el.classList.contains('leaflet-container') ||
    (first && first.classList && (first.classList.contains('highcharts-container') || first.classList.contains('leaflet-container'))) ||
    !!el.closest('.highcharts-container, .leaflet-container')
  );
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  readFactor();
  window.addEventListener('resize', readFactor);
  window.addEventListener('load', readFactor);
  document.addEventListener('DOMContentLoaded', readFactor);
  window.matchMedia('print').addEventListener('change', readFactor);

  const nativeRect = Element.prototype.getBoundingClientRect;
  Element.prototype.getBoundingClientRect = function () {
    const r = nativeRect.call(this);
    if (factor === 1 || isNativeMeasured(this)) {
      return r;
    }

    return new DOMRect(r.x / factor, r.y / factor, r.width / factor, r.height / factor);
  };

  ['innerWidth', 'innerHeight'].forEach(name => {
    const desc = Object.getOwnPropertyDescriptor(window, name);
    if (desc && desc.get && desc.configurable) {
      Object.defineProperty(window, name, { configurable: true, get: () => desc.get.call(window) / factor });
    }
  });
}
