// Each log in WIMS Logs has its own colour and icon: used by the lists, the cards and the record viewer, so they always agree.
const LOOKS = {
  weather: { icon: 'cloud', tone: '#0284c7', tone2: '#38bdf8' },
  delivery: { icon: 'email', tone: '#7c3aed', tone2: '#a78bfa' },
  downloads: { icon: 'download', tone: '#059669', tone2: '#34d399' },
  alerts: { icon: 'bell', tone: '#d97706', tone2: '#fbbf24' },
  system: { icon: 'server', tone: '#4f46e5', tone2: '#818cf8' },
  slow: { icon: 'lightning-bolt', tone: '#e11d48', tone2: '#fb7185' },
};

export function logLook(key) {
  return LOOKS[key] || { icon: 'archive-box', tone: '#4f46e5', tone2: '#818cf8' };
}
