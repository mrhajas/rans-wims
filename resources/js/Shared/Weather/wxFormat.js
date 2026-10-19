// Small formatting helpers for the Weather pages. Times from the server are local ('YYYY-MM-DD HH:mm:ss', Africa/Dar_es_Salaam).
const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function parse(s) {
  if (!s) return null;
  const m = String(s).replace('T', ' ').match(/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/);
  return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)) : null;
}

export const hhmm = s => (s ? String(s).replace('T', ' ').slice(11, 16) : '');
export function dayLabel(s) { const d = parse(s); return d ? `${DOW[d.getDay()]} ${d.getDate()} ${MON[d.getMonth()]}` : ''; }
export function dateOnly(s) { const d = parse(s); return d ? `${d.getDate()} ${MON[d.getMonth()]} ${d.getFullYear()}` : ''; }
export function dateTime(s) { const d = parse(s); return d ? `${d.getDate()} ${MON[d.getMonth()]}, ${hhmm(s)}` : ''; }
export function hourLabel(s, i) {
  const d = parse(s);
  if (!d) return '';
  const now = new Date();
  const same = d.toDateString() === now.toDateString();
  return same || i === 0 ? hhmm(s) : `${DOW[d.getDay()]} ${hhmm(s)}`;
}
export function ago(mins, t = x => x) {
  if (mins === null || mins === undefined) return '';
  if (mins < 2) return t('just now');
  if (mins < 90) return mins + ' ' + t('min ago');
  const h = Math.floor(mins / 60);
  return h < 36 ? `${h} ${t('h ago')}` : `${Math.floor(h / 24)} ${t('days ago')}`;
}
export const num = (v, d = 0) => (v === null || v === undefined || v === '' ? '-' : Number(v).toFixed(d).replace(/\.0+$/, ''));
export function compass(deg) {
  if (deg === null || deg === undefined) return '';
  return ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'][Math.round(Number(deg) / 22.5) % 16];
}
