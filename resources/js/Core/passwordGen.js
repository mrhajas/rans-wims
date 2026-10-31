// A new password that passes the server's rule (8 or more characters with an upper case letter, a lower case letter and a number: App\Support\PasswordPolicy)
// but is simple: 10 letters and digits, no symbols, no look-alike characters (O / 0, l / 1 / I), so it is easy to read out and to type.
const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const LOWER = 'abcdefghijkmnpqrstuvwxyz';
const DIGITS = '23456789';

function randomInt(max) {
  const c = window.crypto || window.msCrypto;
  if (c && c.getRandomValues) {
    const a = new Uint32Array(1);
    const limit = Math.floor(0xffffffff / max) * max; // no bias towards the first characters
    do { c.getRandomValues(a); } while (a[0] >= limit);
    return a[0] % max;
  }

  return Math.floor(Math.random() * max);
}

export function generatePassword(length = 10) {
  const all = UPPER + LOWER + DIGITS;
  const pick = set => set[randomInt(set.length)];
  const chars = [pick(UPPER), pick(LOWER), pick(DIGITS)];
  while (chars.length < Math.max(length, 8)) chars.push(pick(all));
  for (let i = chars.length - 1; i > 0; i--) {
    const j = randomInt(i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }

  return chars.join('');
}
