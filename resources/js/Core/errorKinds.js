import i18n from '@/Core/i18n';

// Tells what KIND of problem a set of form errors is, so the red pill can say "Not enough stock" or "Missing information" instead of a bare "Not saved"
// with a count. It reads the wording of the server's own messages (the same sentences the fields show), so it works for every module without each page
// having to name its errors.
// the app's keys use ":name" placeholders, which vue-i18n does not fill by itself (see app.js)
const t = (key, params) => {
  let out = String(key);
  try {
    out = i18n.global.t(key);
  } catch (e) {
    // an untranslated key shows as written
  }

  return params ? out.replace(/:([A-Za-z_]\w*)/g, (m, k) => (Object.prototype.hasOwnProperty.call(params, k) ? String(params[k]) : m)) : out;
};

// order matters: the first kind whose pattern matches wins
const KINDS = [
  { key: 'password', label: 'Incorrect password', re: /(not your|wrong|incorrect) password|password is not (right|correct)/i },
  { key: 'balance', label: 'Amount too high', re: /still to (be )?move|left to pay|at most the balance|balance available|more than .*balance|exceeds? .*(balance|amount|limit)|over the (balance|limit)|outstanding|still payable|remaining to/i },
  { key: 'stock', label: 'Not enough stock', re: /in stock|no stock|available quantity|not enough|insufficient|out of stock|only :?\w+ (is|are) (at|left|available)|cannot move|exceeds? (the )?(available|stock)|more than (is )?available/i },
  { key: 'duplicate', label: 'Already exists', re: /already (been )?(taken|exists?|used|in use|on|added|recorded|started|decided|ended)|has already|is on the list twice|duplicate|must be unique|used twice|twice/i },
  { key: 'denied', label: 'Not allowed', re: /not allowed|not authori[sz]ed|permission|forbidden|can ?not be (edited|changed|deleted|removed|generated|sent)|no longer be (edited|changed)|is (halted|locked|closed|completed)|stock movement|\bcan only\b|only an? [\w ]+ (can|may)|only a (draft|rejected|pending)|not enabled|(is|are) (currently )?disabled|^only\b.*\bcan\b|cannot also|requires?\b.*\b(access|location|permission)/i },
  { key: 'range', label: 'Invalid value', re: /greater than|less than|at most|at least \d|no more than|numeric|0 or more|must be a number/i },
  { key: 'required', label: 'Missing information', re: /is required|are required|required\b|please (choose|select|enter|add|fill)|^(choose|select|enter|add|fill|describe|give|provide|type|write|pick)\b|add at least|at least one|must (be )?(selected|chosen|filled)|missing|cannot be (empty|blank)|can ?not be empty|is not set|not been set|needs (a|an|the)\b/i },
  { key: 'notfound', label: 'Not found', re: /not found|no longer exists?|could not be (read|found|opened)|is not on (this|the)|not a [\w &]+ worker|not currently live/i },
  { key: 'order', label: 'Not ready yet', re: /\bfirst\b|still waiting|waiting for|must be (issued|approved|paid|finished)/i },
  { key: 'invalid', label: 'Invalid value', re: /must be|should be|valid|greater than|less than|at least|at most|no more than|no less than|numeric|number|digits|format|match|different|between|exceed|too (long|short|large|small|many|few)|characters|date|does not belong|not exist|invalid|incorrect|decimal|not a real|up to \d|maximum|minimum/i },
];

/** The kind of one message: { key, label } or null when it is not recognised. */
export function kindOf(message) {
  const m = String(message || '');
  for (const k of KINDS) if (k.re.test(m)) return { key: k.key, label: k.label };

  return null;
}

const firstOf = v => (Array.isArray(v) ? v[0] : v);

/**
 * errors: { field: message | [messages] }
 * Returns { title, text }. title is the bold line of the pill, text the detail under it.
 * `fallback` is the pill's usual wording ("Not saved") for an error that is not recognised.
 */
export function describeErrors(errors, fallback) {
  const list = Object.values(errors || {}).map(firstOf).filter(Boolean).map(String);
  const n = list.length;
  if (!n) return { title: fallback || t('Not saved'), text: '' };

  const kinds = list.map(kindOf);
  const distinct = [...new Set(kinds.map(k => (k ? k.label : '')))];
  const head = list[0];
  if (n === 1) {
    return { title: kinds[0] ? t(kinds[0].label) : fallback || t('Not saved'), text: head };
  }
  if (distinct.length === 1 && distinct[0]) {
    return { title: t(distinct[0]), text: head + ' ' + t('(+:n more)', { n: n - 1 }) };
  }
  // several kinds: say how many of each ("2 Not enough stock, 1 Missing information")
  const counts = {};
  kinds.forEach(k => {
    const label = k ? t(k.label) : t('Other');
    counts[label] = (counts[label] || 0) + 1;
  });
  const summary = Object.entries(counts)
    .sort((a, b) => b[1] - a[1])
    .map(([label, c]) => c + ' ' + label)
    .join(', ');

  return { title: t(':n problems to fix', { n }), text: summary + '. ' + head };
}

/** A single message (a flash error from the server). */
export function describeMessage(message, fallback) {
  return describeErrors({ message }, fallback);
}
