// A single shared `v-tooltip="text"` directive, replacing native `title`
// attribute tooltips app-wide (starting with Transport Routes, which
// had ~25 of them across 7 pages). A native title tooltip is browser-styled,
// can't escape an ancestor's `overflow: hidden`/`overflow-x: auto` (exactly
// the clipping bug already fixed once for the Payment Vouchers list's PV
// Number tooltip and the Transport Routes Records list's Verify/amount-diff
// tooltips), and isn't responsive. Rather than re-implement that Teleport +
// getBoundingClientRect positioning in every file that needs a tooltip, this
// directive does it once: one shared tooltip <div> appended to <body>,
// reused (repositioned) for whichever element is currently hovered, so it
// never gets clipped by a table's overflow-x-auto wrapper and never runs
// off a narrow/mobile viewport.
//
// Usage: `v-tooltip="$t('Edit')"` in place of `:title="$t('Edit')"`.
// A falsy value (null/''/false) simply shows nothing, so `v-tooltip="cond ? $t('X') : null"` works for a conditional tooltip.

let tooltipEl = null;
let hideTimer = null;

function ensureTooltipEl() {
  if (tooltipEl) return tooltipEl;
  tooltipEl = document.createElement('div');
  tooltipEl.className = 'v-tooltip-el';
  document.body.appendChild(tooltipEl);
  return tooltipEl;
}

function position(el, text) {
  const tip = ensureTooltipEl();
  tip.textContent = text;
  tip.style.visibility = 'hidden';
  tip.style.display = 'block';

  const rect = el.getBoundingClientRect();
  const gap = 8;
  // Measure after setting text/display so the width reflects this tooltip's
  // own content, then clamp it to the viewport before ever showing it.
  const maxWidth = Math.min(260, window.innerWidth - 24);
  tip.style.maxWidth = maxWidth + 'px';
  const tipRect = tip.getBoundingClientRect();

  const openUp = rect.top > tipRect.height + gap * 2 || window.innerHeight - rect.bottom < tipRect.height + gap * 2;
  const left = Math.max(8, Math.min(rect.left + rect.width / 2 - tipRect.width / 2, window.innerWidth - tipRect.width - 8));

  tip.style.left = left + 'px';
  if (openUp) {
    tip.style.top = '';
    tip.style.bottom = window.innerHeight - rect.top + gap + 'px';
  } else {
    tip.style.bottom = '';
    tip.style.top = rect.bottom + gap + 'px';
  }
  tip.style.visibility = 'visible';
}

function show(el) {
  const text = el._tooltipText;
  if (!text) return;
  clearTimeout(hideTimer);
  position(el, text);
}

function hide() {
  hideTimer = setTimeout(() => {
    if (tooltipEl) tooltipEl.style.display = 'none';
  }, 60);
}

export default {
  mounted(el, binding) {
    el._tooltipText = binding.value;
    el._tooltipEnter = () => show(el);
    el._tooltipLeave = hide;
    el.addEventListener('mouseenter', el._tooltipEnter);
    el.addEventListener('mouseleave', el._tooltipLeave);
    el.addEventListener('click', el._tooltipLeave);
  },
  updated(el, binding) {
    el._tooltipText = binding.value;
  },
  beforeUnmount(el) {
    el.removeEventListener('mouseenter', el._tooltipEnter);
    el.removeEventListener('mouseleave', el._tooltipLeave);
    el.removeEventListener('click', el._tooltipLeave);
    if (tooltipEl) tooltipEl.style.display = 'none';
  },
};
