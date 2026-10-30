/**
 * Required fields, everywhere in WIMS.
 *
 * A compulsory field carries an asterisk in its label (`<b class="wims-req">*</b>`; a bare "*" in a label's own tag is picked up too, and a field with the
 * native `required` attribute but no asterisk gets one drawn on its label). This keeps three states in step with what the person has typed:
 *   empty -> the asterisk glows maroon and the field itself carries a soft maroon glow
 *   ok    -> the asterisk glows green and the field is calm
 *   bad   -> the server (or a check) has rejected it: the asterisk stays maroon and the field gets the red error glow (see app.css)
 * Only data attributes are written (`data-req` on the asterisk or label, `data-req-state` on the field), which Vue never patches, so nothing here fights a
 * component's own rendering. Values change without DOM events (a date picked from a calendar, a choice from a dropdown), so a light timer checks them.
 */
const CONTROL = 'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([type="button"]):not([type="submit"]):not([type="search"]), select, textarea';
const ERRORS = '.wims-field-error, .ac-err, .sg-error, .ar-err, .wx-error, .pf-err, .tlm-err, .mrf-err, .wc-err, .ss-err, .sm-err, .sa-err, .ps-err, .mt-err, .lic-err, .if-err, .fx-err, .rf-err, .cl-err, .bk-err, .scm-err, .ms-err, .alp-error, .cp-err, .ca-err, .an-err, .asn-err, .sdy-err, .sdf-err, .rs-err, .pm-err, .mrr-err, .lbr-err, .llu-err, .tlb-err, .ty-err, .wcs-err';
const TICK = 400;

const shown = el => !!el && (el.offsetParent !== null || getComputedStyle(el).position === 'fixed') && el.style.display !== 'none';

const filled = el => (el.tagName === 'SELECT' ? el.value !== '' : el.type === 'checkbox' || el.type === 'radio' ? el.checked : String(el.value || '').trim() !== '');

const hasError = el => {
  let a = el;
  for (let i = 0; a && i < 5; i++, a = a.parentElement) {
    for (const m of a.querySelectorAll(':scope > ' + ERRORS.split(', ').join(', :scope > '))) {
      if (shown(m) && m.textContent.trim() !== '') return true;
    }
  }
  return false;
};

/** The field(s) a label belongs to: the one it points at, otherwise the visible fields of its wrapper (a wrapper that holds several required labels is not guessed at). */
const controlsOf = label => {
  const own = label.control;
  if (own && own.matches && (own.matches(CONTROL) || own.type === 'checkbox')) return [own];
  let w = label.parentElement;
  for (let i = 0; w && i < 3; i++, w = w.parentElement) {
    if (w.querySelectorAll('label .wims-req, label[data-req-auto]').length > 1) return [];
    const found = Array.from(w.querySelectorAll(CONTROL)).filter(c => !c.disabled && shown(c));
    if (found.length) return found;
  }
  return [];
};

const glowTarget = c => c.closest('.wdp-trigger') || c;

const stateOf = controls => {
  const c = controls[0];
  if (!c) return null;
  if (hasError(c)) return 'bad';
  return filled(c) ? 'ok' : 'empty';
};

const setField = (controls, state) => {
  controls.forEach((c, i) => {
    const t = glowTarget(c);
    if (i === 0 && state === 'empty') t.setAttribute('data-req-state', 'empty');
    else if (t.hasAttribute('data-req-state')) t.removeAttribute('data-req-state');
  });
};

const adopt = () => {
  // a plain "*" inside a label's own tag becomes a required mark
  document.querySelectorAll('label b:not(.wims-req), label span:not(.wims-req), label i:not(.wims-req), label em:not(.wims-req), label sup:not(.wims-req), label small:not(.wims-req), label strong:not(.wims-req)').forEach(el => {
    if (!el.firstElementChild && el.textContent.trim() === '*') el.classList.add('wims-req');
  });
};

const run = () => {
  if (document.hidden) return;
  adopt();
  const seen = new Set();
  document.querySelectorAll('.wims-req').forEach(mark => {
    const label = mark.closest('label');
    if (!label || !shown(mark)) return;
    const controls = controlsOf(label);
    const state = stateOf(controls) || 'empty';
    if (mark.dataset.req !== state) mark.dataset.req = state;
    if (controls.length) { setField(controls, state); controls.forEach(c => seen.add(glowTarget(c))); }
  });
  // native `required` fields whose label has no asterisk: the label draws one
  document.querySelectorAll('input[required]:not([type="hidden"]), select[required], textarea[required]').forEach(c => {
    if (c.disabled || !shown(c)) return;
    const label = c.labels && c.labels[0];
    if (!label || label.querySelector('.wims-req')) { if (!label) { const t = glowTarget(c); const st = hasError(c) ? 'bad' : filled(c) ? 'ok' : 'empty'; st === 'empty' ? t.setAttribute('data-req-state', 'empty') : t.removeAttribute('data-req-state'); } return; }
    const st = hasError(c) ? 'bad' : filled(c) ? 'ok' : 'empty';
    if (label.dataset.reqAuto !== st) label.dataset.reqAuto = st;
    setField([c], st);
    seen.add(glowTarget(c));
  });
  // a field that is no longer governed by any mark keeps no glow
  document.querySelectorAll('[data-req-state]').forEach(t => { if (!seen.has(t) && !t.matches('input[required], select[required], textarea[required]')) t.removeAttribute('data-req-state'); });
};

export const initRequiredFields = router => {
  let queued = false;
  const soon = () => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; run(); });
  };
  ['input', 'change', 'focusout', 'click', 'keyup'].forEach(ev => document.addEventListener(ev, soon, true));
  new MutationObserver(soon).observe(document.body, { childList: true, subtree: true });
  window.setInterval(run, TICK);
  if (router && router.on) {
    router.on('finish', () => window.setTimeout(run, 60));
    router.on('error', () => window.setTimeout(run, 120));
  }
  run();
};
