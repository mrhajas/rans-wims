// Exact money for the Contract Payment Request pages: whole cents as BigInt, never floating point.
export const toCents = v => {
  const s = String(v === null || v === undefined ? '' : v).replace(/,/g, '').trim();
  if (!/^-?\d+(\.\d{0,2})?$/.test(s)) return null;
  const neg = s[0] === '-'; const [i, f = ''] = s.replace('-', '').split('.');
  const c = BigInt(i) * BigInt(100) + BigInt((f + '00').slice(0, 2));
  return neg ? -c : c;
};

// a plain decimal string ("1234.50") from cents; the page formats it with the currency
export const fromCents = c => {
  const neg = c < BigInt(0); const a = neg ? -c : c;
  return (neg ? '-' : '') + (a / BigInt(100)).toString() + '.' + (a % BigInt(100)).toString().padStart(2, '0');
};

export const ZERO = BigInt(0);
