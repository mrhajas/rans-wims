// Run with:  node tests/js/itemCatalogue.test.mjs
// The browser-side item search (resources/js/Core/itemCatalogue.js) must follow the server's rules (Item::scopeSearch). It is loaded here without a
// browser: the two imports are replaced by stubs and a tiny fixture list stands in for the answer of items.catalogue.
import fs from 'fs';
import assert from 'assert';

const src = fs.readFileSync(new URL('../../resources/js/Core/itemCatalogue.js', import.meta.url), 'utf8')
  .replace(/^import .*$/gm, '')
  .replace(/export function/g, 'function');

const rows = [
  { id: 1, code: 'RSI-0001', name: 'CEMENT 50KG', sku: null, cat: 'Building cement' },
  { id: 2, code: 'RSI-0002', name: 'WHITE CEMENT 50KG', sku: 'WC-50', cat: 'Building cement' },
  { id: 3, code: 'RSI-0562', name: '1 GANG 2 WAY SWITCH SOCKET', sku: null, cat: 'Electrical electrical | Switches switch' },
  { id: 4, code: 'RSI-0563', name: 'PVC PIPE 20MM', sku: null, cat: 'Plumbing plumbing' },
  { id: 5, code: 'RSI-0100', name: 'CABLE 100% COPPER', sku: null, cat: '' },
  { id: 6, code: 'RSI-0101', name: 'NAIL_BOX', sku: null, cat: '' },
];
const catalogue = { version: 'v1', items: rows.map(r => ({ ...r, unit: { id: 1, code: 'pc' }, variations: [] })) };

let calls = [];
const window = { axios: { get: async (url, opts) => { calls.push(opts.headers); return { status: 200, data: JSON.parse(JSON.stringify(catalogue)) }; } } };
const mod = new Function('router', 'itemText', 'window', 'route', src + '; return { searchCatalogue, ensureCatalogue, canonicalCode, resetCatalogue, catalogueReady };')(
  { on() {} },
  (n, c) => String(n).trim() + (c ? ' (' + c + ')' : ''),
  window,
  () => '/search/items/catalogue',
);
const ids = (term, o) => mod.searchCatalogue(1, term, o).map(r => r.id);

// not loaded: the caller must ask the server
assert.strictEqual(mod.searchCatalogue(1, 'cement'), null);
assert.strictEqual(await mod.ensureCatalogue(1), true);
assert.ok(mod.catalogueReady(1));
assert.strictEqual(mod.catalogueReady(2), false, 'another person never sees this list');

// code forms
assert.strictEqual(mod.canonicalCode('562'), 'RSI-0562');
assert.strictEqual(mod.canonicalCode('RSI 562'), 'RSI-0562');
assert.strictEqual(mod.canonicalCode('rsi-0562'), 'RSI-0562');
assert.strictEqual(mod.canonicalCode('cement'), null);

// words: every word must be found; case does not matter; the category counts
assert.deepStrictEqual(ids('cement'), [1, 2]);
assert.deepStrictEqual(ids('CEMENT white'), [2]);
assert.deepStrictEqual(ids('1 gang 2 way'), [3]);
assert.deepStrictEqual(ids('electrical'), [3]);
assert.deepStrictEqual(ids('wc-50'), [2]);
assert.deepStrictEqual(ids('nothing like this'), []);

// the typed code comes first, in every spelling
assert.deepStrictEqual(ids('562')[0], 3);
assert.deepStrictEqual(ids('RSI 562')[0], 3);
assert.deepStrictEqual(ids('rsi-0563'), [4]);

// % and _ are the characters themselves, as on the server
assert.deepStrictEqual(ids('%'), [5]);
assert.deepStrictEqual(ids('_'), [6]);

// the empty search lists the first items; the labelled list is by name and carries NAME (CODE)
assert.strictEqual(ids('').length, 6);
const labelled = mod.searchCatalogue(1, 'cement', { labelled: true });
assert.deepStrictEqual(labelled.map(r => r.label), ['CEMENT 50KG (RSI-0001)', 'WHITE CEMENT 50KG (RSI-0002)']);

// the search text is not part of the item, and every answer is a fresh copy the form may change
assert.ok(!('cat' in labelled[0]));
labelled[0].selected = { variations: [1] };
assert.ok(!('selected' in mod.searchCatalogue(1, 'cement')[0]));

// limit
assert.strictEqual(mod.searchCatalogue(1, '', { limit: 3 }).length, 3);

// a second look inside 30 seconds does not ask the server again
const before = calls.length;
await mod.ensureCatalogue(1);
assert.strictEqual(calls.length, before);

// a different person signed in: the list is dropped and fetched again
await mod.ensureCatalogue(2);
assert.strictEqual(mod.catalogueReady(1), false);
assert.strictEqual(mod.catalogueReady(2), true);

console.log('itemCatalogue: all checks passed');
