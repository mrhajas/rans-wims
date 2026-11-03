// Run with:  node tests/js/moneyInput.test.mjs
// The rules of the amount fields (resources/js/Core/moneyInput.js): what is typed or pasted becomes a plain number, and the plain number is shown with commas.
import assert from 'assert';
import { cleanMoney, formatMoney } from '../../resources/js/Core/moneyInput.js';

const o = { decimals: 4, negative: true };
const c = (v, opts = o) => cleanMoney(v, opts);

// typing and pasting
assert.strictEqual(c('1234567.5'), '1234567.5');
assert.strictEqual(c('1,234,567.50'), '1234567.50');
assert.strictEqual(c(' 12,345,678.9 TZS'), '12345678.9');
assert.strictEqual(c('abc'), '');
assert.strictEqual(c(''), '');
assert.strictEqual(c(null), '');
assert.strictEqual(c(1500), '1500');
assert.strictEqual(c('80000.00'), '80000.00');
assert.strictEqual(c('1.2.3'), '1.23'); // a second dot is dropped
assert.strictEqual(c('1.23456789'), '1.2345'); // no more decimals than the field allows
assert.strictEqual(c('1.23456789', { decimals: 8, negative: true }), '1.23456789'); // a rate keeps more
assert.strictEqual(c('12.5', { decimals: 0, negative: true }), '12');
assert.strictEqual(c('007'), '7');
assert.strictEqual(c('0.5'), '0.5');
assert.strictEqual(c('.5'), '.5');
assert.strictEqual(c('5.'), '5.'); // while the person is still typing the decimals

// the minus sign only where a negative is allowed
assert.strictEqual(c('-1,200'), '-1200');
assert.strictEqual(c('-1,200', { decimals: 4, negative: false }), '1200');
assert.strictEqual(c('12-3'), '123'); // a minus in the middle is not a minus

// shown with commas, the plain number untouched
assert.strictEqual(formatMoney('1234567.5'), '1,234,567.5');
assert.strictEqual(formatMoney('999'), '999');
assert.strictEqual(formatMoney('1000'), '1,000');
assert.strictEqual(formatMoney('-1234.5'), '-1,234.5');
assert.strictEqual(formatMoney('0.5'), '0.5');
assert.strictEqual(formatMoney('.5'), '.5');
assert.strictEqual(formatMoney('5.'), '5.');
assert.strictEqual(formatMoney(''), '');
assert.strictEqual(formatMoney('1234567890123'), '1,234,567,890,123');

// what is shown always cleans back to what it came from
for (const v of ['0', '12', '1234.56', '-98765.4321', '1000000', '.75']) assert.strictEqual(c(formatMoney(c(v))), c(v));

console.log('moneyInput: all checks passed');
