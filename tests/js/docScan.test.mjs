// Document scanning maths on a made-up photo. Run: node tests/js/docScan.test.mjs
import assert from 'node:assert/strict';
import { detectQuad, warpPerspective, outputSize, cleanPage, toGray } from '../../resources/js/Core/docScan.js';

let passed = 0;
const test = (n, f) => { f(); passed++; console.log('  ok  ', n); };

// a 480x360 "photo": dark table, a slightly turned white page with dark text lines and an uneven shadow
const W = 480, H = 360;
const page = [{ x: 90, y: 50 }, { x: 400, y: 70 }, { x: 380, y: 320 }, { x: 70, y: 290 }]; // tl tr br bl in pixels
const inside = (px, py) => { let s = 0; for (let i = 0; i < 4; i++) { const a = page[i], b = page[(i + 1) % 4]; s += Math.sign((b.x - a.x) * (py - a.y) - (b.y - a.y) * (px - a.x)); } return Math.abs(s) === 4; };
const data = new Uint8ClampedArray(W * H * 4);
for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
  const o = (y * W + x) * 4;
  let v = 55 + ((x * 7 + y * 13) % 9); // the table, with a little noise
  if (inside(x, y)) {
    v = 235 - Math.round((x / W) * 55); // the paper, darker towards the right (shadow)
    if ((y % 22) < 4 && x > 120 && x < 340) v -= 150; // lines of text
  }
  data[o] = data[o + 1] = data[o + 2] = v; data[o + 3] = 255;
}
const photo = { data, width: W, height: H };

test('the paper is found and its corners are close to the real ones', () => {
  const r = detectQuad(photo);
  assert.equal(r.found, true);
  r.quad.forEach((p, i) => {
    const e = Math.hypot(p.x * W - page[i].x, p.y * H - page[i].y);
    assert.ok(e < 14, 'corner ' + i + ' is ' + e.toFixed(1) + ' px off');
  });
});

test('a photo with no paper falls back to a frame with a margin', () => {
  const flat = new Uint8ClampedArray(W * H * 4).fill(120);
  for (let i = 3; i < flat.length; i += 4) flat[i] = 255;
  const r = detectQuad({ data: flat, width: W, height: H });
  assert.equal(r.found, false);
  assert.ok(r.quad[0].x > 0.04 && r.quad[2].x < 0.96);
});

test('the straightened page has the right proportions and no table left in it', () => {
  const q = detectQuad(photo).quad;
  const px = q.map(p => ({ x: p.x * W, y: p.y * H }));
  const size = outputSize(px);
  assert.ok(size.width > 200 && size.height > 200);
  const out = warpPerspective(photo, q, size.width, size.height);
  const g = toGray(out);
  let dark = 0;
  for (let y = 6; y < out.height - 6; y++) for (let x = 6; x < out.width - 6; x++) if (g[y * out.width + x] < 90) dark++;
  // dark pixels are the text lines only (a small share), not the dark table
  assert.ok(dark / (out.width * out.height) < 0.2, 'dark share ' + (dark / (out.width * out.height)).toFixed(2));
});

test('cleaning evens out the shadow: paper becomes (nearly) white everywhere, text stays dark', () => {
  const q = detectQuad(photo).quad;
  const px = q.map(p => ({ x: p.x * W, y: p.y * H }));
  const size = outputSize(px);
  const out = warpPerspective(photo, q, size.width, size.height);
  const clean = cleanPage(out, 'color');
  const g = toGray(clean);
  const sample = (fx, fy) => g[Math.floor(fy * clean.height) * clean.width + Math.floor(fx * clean.width)];
  // empty margins left and right of the text have very different brightness before cleaning, equal after
  const left = sample(0.08, 0.12), right = sample(0.92, 0.12);
  assert.ok(left > 215 && right > 215, 'paper left ' + left + ' right ' + right);
  assert.ok(Math.abs(left - right) < 18);
  const bw = cleanPage(out, 'bw'), gb = toGray(bw);
  assert.ok([...gb].every(v => v === 0 || v === 255));
  assert.ok(gb[Math.floor(0.12 * bw.height) * bw.width + Math.floor(0.92 * bw.width)] === 255);
});

console.log(passed + ' passed');
