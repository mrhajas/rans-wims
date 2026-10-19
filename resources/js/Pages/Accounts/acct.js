// Shared by every Accounts Tracking page: money is shown from decimal STRINGS (never through floating point) with each currency's own decimal places.
export function fmtMoney(v, cur, curMap) {
  if (v === null || v === undefined || v === '') return '-';
  let s = String(v).trim();
  const neg = s.startsWith('-');
  if (neg) s = s.slice(1);
  const [i0, f0 = ''] = s.split('.');
  const i = i0.replace(/\D/g, '') || '0';
  const p = curMap && curMap[cur] ? curMap[cur].precision : 2;
  const frac = (f0.replace(/\D/g, '') + '0000000').slice(0, p + 1); // the places kept, plus the digit that decides rounding
  let n = BigInt(i + frac.slice(0, p));
  if (frac[p] >= '5') n += 1n; // half up, away from zero, like the server
  const str = n.toString().padStart(p + 1, '0');
  const ip = (p ? str.slice(0, -p) : str).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return (neg && n !== 0n ? '-' : '') + ip + (p ? '.' + str.slice(-p) : '');
}

// ---- exact decimal arithmetic on strings (BigInt), for every figure that is only a preview of what the server will work out. Never floating point:
// a double loses cents on large amounts and rounds .005 the wrong way, so a preview could disagree with the figure the server stores.
const pow10 = k => 10n ** BigInt(k);
function toBig(v) {
  const m = /^(-?)(\d+)(?:\.(\d+))?$/.exec(String(v === null || v === undefined ? '' : v).trim());
  if (!m) return null;
  const f = m[3] || '';
  return { n: BigInt(m[2] + f) * (m[1] ? -1n : 1n), sc: f.length };
}
function fromBig(n, sc) {
  const neg = n < 0n;
  const s = (neg ? -n : n).toString().padStart(sc + 1, '0');
  const body = sc ? s.slice(0, -sc) + '.' + s.slice(-sc) : s;
  return (neg && n !== 0n ? '-' : '') + body;
}
/** Round a scaled integer half up (away from zero) to p decimals. */
function roundBig(n, sc, p) {
  if (sc <= p) return n * pow10(p - sc);
  const d = pow10(sc - p);
  const a = n < 0n ? -n : n;
  let q = a / d;
  if ((a % d) * 2n >= d) q += 1n;
  return n < 0n ? -q : q;
}
/** Is this text a plain decimal number? */
export function isDec(v) { return toBig(v) !== null; }
/** a + b, exact. null when either is not a number. */
export function decAdd(a, b) { const x = toBig(a); const y = toBig(b); if (!x || !y) return null; const sc = Math.max(x.sc, y.sc); return fromBig(x.n * pow10(sc - x.sc) + y.n * pow10(sc - y.sc), sc); }
/** a - b, exact. */
export function decSub(a, b) { const x = toBig(a); const y = toBig(b); if (!x || !y) return null; const sc = Math.max(x.sc, y.sc); return fromBig(x.n * pow10(sc - x.sc) - y.n * pow10(sc - y.sc), sc); }
/** a x b, exact (no rounding). */
export function decMul(a, b) { const x = toBig(a); const y = toBig(b); if (!x || !y) return null; return fromBig(x.n * y.n, x.sc + y.sc); }
/** a x rate rounded half up to p decimals, like the server (Money::convert). */
export function decMulRound(a, rate, p) { const x = toBig(a); const y = toBig(rate); if (!x || !y) return null; return fromBig(roundBig(x.n * y.n, x.sc + y.sc, p), p); }
/** a / rate rounded half up to p decimals, like the server does for the opposite rate. null for a rate of zero. */
export function decDivRound(a, rate, p) {
  const x = toBig(a); const y = toBig(rate);
  if (!x || !y || y.n === 0n) return null;
  // (xn / 10^xs) / (yn / 10^ys) at p decimals = xn * 10^(ys + p) / (yn * 10^xs)
  const num = x.n * pow10(y.sc + p); const den = y.n * pow10(x.sc);
  const neg = (num < 0n) !== (den < 0n);
  const N = num < 0n ? -num : num; const D = den < 0n ? -den : den;
  let q = N / D;
  if ((N % D) * 2n >= D) q += 1n;
  return fromBig(neg ? -q : q, p);
}
/** A decimal text rounded half up to p decimals. */
export function decRound(v, p) { const x = toBig(v); return x ? fromBig(roundBig(x.n, x.sc, p), p) : null; }
/** Is this decimal text exactly zero? */
export function decIsZero(v) { const x = toBig(v); return !!x && x.n === 0n; }

export const acct = {
  computed: {
    // every currency as a searchable choice
    curOptions() { return Object.keys(this.cur || {}).map(k => ({ value: k, label: k })); },
    curOptionsActive() { return Object.entries(this.cur || {}).filter(([, c]) => c.active).map(([k, c]) => ({ value: k, label: k + ' - ' + c.name })); },
  },
  methods: {
    m(v, cur) { return fmtMoney(v, cur, this.cur); },
    mc(v, cur) { return v === null || v === undefined || v === '' ? '-' : cur + ' ' + fmtMoney(v, cur, this.cur); },
    err(k) { return (this.$page.props.errors || {})[k]; },
    statusChip(s) { return { draft: 'lg-chip-slate', submitted: 'lg-chip-amber', approved: 'lg-chip-blue', posted: 'lg-chip-green', rejected: 'lg-chip-red', cancelled: 'lg-chip-slate', reversed: 'lg-chip-violet', active: 'lg-chip-green', inactive: 'lg-chip-slate', suspended: 'lg-chip-amber', closed: 'lg-chip-red', pending: 'lg-chip-amber', open: 'lg-chip-red', acknowledged: 'lg-chip-amber', resolved: 'lg-chip-green' }[s] || 'lg-chip-slate'; },
    sevChip(s) { return { info: 'lg-chip-slate', low: 'lg-chip-blue', medium: 'lg-chip-amber', high: 'lg-chip-red', critical: 'lg-chip-red' }[s] || 'lg-chip-slate'; },
    neg(v) { return String(v).startsWith('-'); },
  },
};
