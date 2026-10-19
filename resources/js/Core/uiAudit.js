// Responsive Audit measuring. Runs against a same-origin frame that is as wide as the width being audited, so every media query is real.
// It only reads the page: nothing is clicked or changed.

const lineCount = el => {
  const tops = new Set();
  const w = el.ownerDocument.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  for (let n = w.nextNode(); n; n = w.nextNode()) {
    if (!n.textContent.trim()) continue;
    const r = el.ownerDocument.createRange();
    r.selectNodeContents(n);
    for (const q of r.getClientRects()) if (q.width > 0) tops.add(Math.round(q.top / 6));
  }
  return tops.size;
};

export function measureFrame(win, { minTarget = 32 } = {}) {
  const doc = win.document;
  const vw = win.innerWidth;
  const found = [];
  const add = (category, el, label, detail) => found.push({ category, selector: sig(el, win), label: (label || '').slice(0, 100), detail: (detail || '').slice(0, 250) });
  const vis = el => { const r = el.getBoundingClientRect(); if (r.width < 1 || r.height < 1) return false; const cs = win.getComputedStyle(el); return cs.visibility !== 'hidden' && cs.display !== 'none' && cs.opacity !== '0'; };
  const inScroller = el => { for (let p = el.parentElement; p && p !== doc.body; p = p.parentElement) { const o = win.getComputedStyle(p).overflowX; if (['auto', 'scroll', 'hidden', 'clip'].includes(o) && p.getBoundingClientRect().right <= vw + 1) return true; } return false; };

  if (doc.documentElement.scrollWidth > vw + 1) add('scroll', doc.documentElement, 'Page', `The page is ${doc.documentElement.scrollWidth}px wide on a ${vw}px screen`);

  const seen = new Set();
  const controls = doc.querySelectorAll("button, a[role=button], [role=tab], input[type=submit], input[type=button], a[class*=btn], [class*='-btn'], [class*=chip]");
  for (const el of controls) {
    if (seen.has(el) || !vis(el)) continue;
    seen.add(el);
    const txt = ((el.innerText || el.value || '') + '').trim();
    const r = el.getBoundingClientRect();
    const short = txt.length > 0 && txt.length <= 38 && !/\n/.test(txt);
    if (short && lineCount(el) > 1) add('wrap', el, txt, `Label is on two lines in a ${Math.round(r.width)}px control`);
    if (r.right > vw + 1 && !inScroller(el)) add('over', el, txt, `Reaches ${Math.round(r.right)}px on a ${vw}px screen`);
    const cs = win.getComputedStyle(el);
    if (short && el.scrollWidth > el.clientWidth + 3 && (cs.overflow !== 'visible' || cs.textOverflow === 'ellipsis')) add('clipped', el, txt, `Needs ${el.scrollWidth}px, has ${el.clientWidth}px`);
    const tag = el.tagName;
    const interactive = tag === 'BUTTON' || tag === 'INPUT' || (tag === 'A' && /btn|button/.test(typeof el.className === 'string' ? el.className : ''));
    if (interactive && (txt || el.querySelector('svg, img')) && (r.height < minTarget || r.width < minTarget) && !hasHitArea(el, win)) add('small', el, txt || el.getAttribute('aria-label') || 'icon', `${Math.round(r.width)} x ${Math.round(r.height)}px, smallest is ${minTarget}px`);
  }
  return dedupe(found);
}

// icon controls that grow their own tap area with an invisible ::after are fine
function hasHitArea(el, win) {
  const a = win.getComputedStyle(el, '::after');
  return a && a.content !== 'none' && a.position === 'absolute' && parseFloat(a.top) < 0;
}

function sig(el, win) {
  const cls = (typeof el.className === 'string' ? el.className : (el.className && el.className.baseVal) || '').trim().split(/\s+/).filter(c => c && !/^(w-|h-|px-|py-|mt-|mb-|ml-|mr-|p-|m-|text-|bg-|flex|inline|block|hidden|md:|sm:|lg:|nav:|print:)/.test(c)).slice(0, 3);
  return (el.tagName || 'page').toLowerCase() + (cls.length ? '.' + cls.join('.') : '');
}

function dedupe(list) {
  const seen = new Set();
  return list.filter(f => { const k = f.category + '|' + f.selector + '|' + f.label; if (seen.has(k)) return false; seen.add(k); return true; });
}

const wait = ms => new Promise(r => setTimeout(r, ms));

/** Opens one page in the frame and measures it. Resolves to { findings } or { error }. */
export async function auditPage(frame, url, opts) {
  return new Promise(resolve => {
    let done = false;
    const finish = v => { if (!done) { done = true; resolve(v); } };
    const timer = setTimeout(() => finish({ error: 'timeout' }), 25000);
    frame.onload = async () => {
      try {
        const win = frame.contentWindow;
        const doc = win.document;
        for (let i = 0; i < 40; i++) {
          const l = doc.getElementById('app-loading');
          if (doc.querySelector('.app-shell, #app') && (!l || l.offsetParent === null || win.getComputedStyle(l).display === 'none')) break;
          await wait(150);
        }
        const style = doc.createElement('style');
        style.textContent = '*{animation:none!important;transition:none!important}';
        doc.head.appendChild(style);
        await wait(700);
        if (!doc.querySelector('.app-shell')) { clearTimeout(timer); return finish({ skipped: true }); }
        const findings = measureFrame(win, opts);
        clearTimeout(timer);
        finish({ findings });
      } catch (e) { clearTimeout(timer); finish({ error: String(e.message || e) }); }
    };
    frame.src = url;
  });
}
