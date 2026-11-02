import i18n from '@/Core/i18n';

// Title Case for the messages about items (stock, quantities, units): every word starts with a capital, except small joining words (of, in, and, to ...), which stay
// lowercase unless they open the message or follow a colon. Letters after the first are never changed, so item names, codes and units keep their own spelling.
// Only English is changed (the joining words of other languages are not known here). Same rule as title_words() on the server.
const SMALL = new Set(['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'of', 'on', 'onto', 'or', 'per', 'than', 'the', 'to', 'via', 'vs', 'with']);

export function titleWords(text) {
  const s = String(text ?? '');
  const loc = i18n && i18n.global && i18n.global.locale ? i18n.global.locale.value || i18n.global.locale : 'en';
  if (!s || loc !== 'en') return s;
  let first = true;
  let afterColon = false;
  return s
    .split(/(\s+)/)
    .map(part => {
      if (part === '' || /^\s+$/.test(part)) return part;
      const bare = part.replace(/^[.,:;()"']+|[.,:;()"']+$/g, '').toLowerCase();
      const capital = first || afterColon || !SMALL.has(bare);
      const out = capital ? part.replace(/^([("']*)(\p{Ll})/u, (m, a, b) => a + b.toUpperCase()) : part;
      first = false;
      afterColon = out.endsWith(':');
      return out;
    })
    .join('');
}

/** "NAME (CODE)" as plain text, for messages. */
export function itemText(name, code) {
  const c = String(code ?? '').trim();
  return String(name ?? '').trim() + (c ? ' (' + c + ')' : '');
}
