// Document scanning, in plain JavaScript (no libraries, nothing leaves the browser): find the paper in a photo, cut it out straight
// (perspective correction), and clean the picture (even out shadows and the background, make the paper white and the text dark).
// Every function works on { data: Uint8ClampedArray (RGBA), width, height } so it can be tested without a browser.

const clamp255 = v => (v < 0 ? 0 : v > 255 ? 255 : v);

export function toGray(img) {
  const { data, width, height } = img;
  const g = new Uint8ClampedArray(width * height);
  for (let i = 0, p = 0; i < g.length; i++, p += 4) g[i] = (data[p] * 77 + data[p + 1] * 150 + data[p + 2] * 29) >> 8;
  return g;
}

/** Box blur (separable, running sums). */
export function boxBlur(src, w, h, r) {
  if (r < 1) return Uint8ClampedArray.from(src);
  const tmp = new Float32Array(w * h), out = new Uint8ClampedArray(w * h), d = 2 * r + 1;
  for (let y = 0; y < h; y++) {
    let s = 0; const row = y * w;
    for (let x = -r; x <= r; x++) s += src[row + Math.min(w - 1, Math.max(0, x))];
    for (let x = 0; x < w; x++) {
      tmp[row + x] = s / d;
      s += src[row + Math.min(w - 1, x + r + 1)] - src[row + Math.max(0, x - r)];
    }
  }
  for (let x = 0; x < w; x++) {
    let s = 0;
    for (let y = -r; y <= r; y++) s += tmp[Math.min(h - 1, Math.max(0, y)) * w + x];
    for (let y = 0; y < h; y++) {
      out[y * w + x] = s / d;
      s += tmp[Math.min(h - 1, y + r + 1) * w + x] - tmp[Math.max(0, y - r) * w + x];
    }
  }
  return out;
}

/** Otsu's threshold: the grey level that best splits dark from bright. */
export function otsu(gray) {
  const hist = new Array(256).fill(0);
  for (let i = 0; i < gray.length; i++) hist[gray[i]]++;
  const total = gray.length;
  let sum = 0;
  for (let t = 0; t < 256; t++) sum += t * hist[t];
  let sumB = 0, wB = 0, best = 0, thr = 127;
  for (let t = 0; t < 256; t++) {
    wB += hist[t];
    if (!wB) continue;
    const wF = total - wB;
    if (!wF) break;
    sumB += t * hist[t];
    const mB = sumB / wB, mF = (sum - sumB) / wF, between = wB * wF * (mB - mF) * (mB - mF);
    if (between > best) { best = between; thr = t; }
  }
  return thr;
}

function majority(mask, w, h) {
  const out = new Uint8Array(w * h);
  for (let y = 1; y < h - 1; y++) for (let x = 1; x < w - 1; x++) {
    let n = 0;
    for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) n += mask[(y + dy) * w + x + dx];
    out[y * w + x] = n >= 5 ? 1 : 0;
  }
  return out;
}

/** The biggest connected bright region of a mask (4-connected flood fill); returns its pixel indexes. */
function biggestRegion(mask, w, h) {
  const seen = new Uint8Array(w * h);
  let best = [];
  const stack = [];
  for (let start = 0; start < w * h; start++) {
    if (!mask[start] || seen[start]) continue;
    const region = [];
    stack.push(start); seen[start] = 1;
    while (stack.length) {
      const p = stack.pop(); region.push(p);
      const x = p % w, y = (p - x) / w;
      if (x > 0 && mask[p - 1] && !seen[p - 1]) { seen[p - 1] = 1; stack.push(p - 1); }
      if (x < w - 1 && mask[p + 1] && !seen[p + 1]) { seen[p + 1] = 1; stack.push(p + 1); }
      if (y > 0 && mask[p - w] && !seen[p - w]) { seen[p - w] = 1; stack.push(p - w); }
      if (y < h - 1 && mask[p + w] && !seen[p + w]) { seen[p + w] = 1; stack.push(p + w); }
    }
    if (region.length > best.length) best = region;
  }
  return best;
}

const polygonArea = q => Math.abs(q.reduce((a, p, i) => { const n = q[(i + 1) % 4]; return a + (p.x * n.y - n.x * p.y); }, 0)) / 2;

/** A sensible starting frame when no paper can be found: the picture with a small margin. */
export const defaultQuad = (m = 0.06) => [{ x: m, y: m }, { x: 1 - m, y: m }, { x: 1 - m, y: 1 - m }, { x: m, y: 1 - m }];

/**
 * Finds the paper: the biggest bright region, then its four corners. Returns { quad: [tl, tr, br, bl] as 0..1 fractions of the picture,
 * found: boolean }. Pass a SMALL copy of the picture (about 400 px wide) for speed.
 */
export function detectQuad(img) {
  const { width: w, height: h } = img;
  const gray = boxBlur(toGray(img), w, h, 2);
  const t = otsu(gray);
  let mask = new Uint8Array(w * h);
  for (let i = 0; i < mask.length; i++) mask[i] = gray[i] > t ? 1 : 0;
  mask = majority(majority(mask, w, h), w, h);
  const region = biggestRegion(mask, w, h);
  if (region.length < w * h * 0.12 || region.length > w * h * 0.985) return { quad: defaultQuad(), found: false };
  let tl = Infinity, br = -Infinity, tr = -Infinity, bl = Infinity;
  let pTL, pBR, pTR, pBL;
  for (const p of region) {
    const x = p % w, y = (p - x) / w, s = x + y, d = x - y;
    if (s < tl) { tl = s; pTL = { x, y }; }
    if (s > br) { br = s; pBR = { x, y }; }
    if (d > tr) { tr = d; pTR = { x, y }; }
    if (d < bl) { bl = d; pBL = { x, y }; }
  }
  const q = [pTL, pTR, pBR, pBL].map(p => ({ x: (p.x + 0.5) / w, y: (p.y + 0.5) / h }));
  if (polygonArea(q) < 0.1) return { quad: defaultQuad(), found: false };

  return { quad: q, found: true };
}

/** Solves the 8 numbers of the perspective transform that maps the unit rectangle onto the four given points. */
function homography(dst, src) {
  const A = [], b = [];
  for (let i = 0; i < 4; i++) {
    const { x, y } = dst[i], { x: u, y: v } = src[i];
    A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]); b.push(u);
    A.push([0, 0, 0, x, y, 1, -v * x, -v * y]); b.push(v);
  }
  const n = 8;
  for (let i = 0; i < n; i++) {
    let max = i;
    for (let r = i + 1; r < n; r++) if (Math.abs(A[r][i]) > Math.abs(A[max][i])) max = r;
    [A[i], A[max]] = [A[max], A[i]]; [b[i], b[max]] = [b[max], b[i]];
    for (let r = i + 1; r < n; r++) {
      const f = A[r][i] / (A[i][i] || 1e-12);
      for (let c = i; c < n; c++) A[r][c] -= f * A[i][c];
      b[r] -= f * b[i];
    }
  }
  const x = new Array(n).fill(0);
  for (let i = n - 1; i >= 0; i--) {
    let s = b[i];
    for (let c = i + 1; c < n; c++) s -= A[i][c] * x[c];
    x[i] = s / (A[i][i] || 1e-12);
  }
  return x;
}

/** The size the straightened page should have: the length of its edges, snapped to A4 when it is nearly A4. */
export function outputSize(quadPx, maxSide = 1800) {
  const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
  let w = (dist(quadPx[0], quadPx[1]) + dist(quadPx[3], quadPx[2])) / 2;
  let h = (dist(quadPx[0], quadPx[3]) + dist(quadPx[1], quadPx[2])) / 2;
  const long = Math.max(w, h), short = Math.min(w, h);
  if (Math.abs(long / short - 1.414) < 0.1) { if (w >= h) h = w / 1.414; else w = h / 1.414; }
  const k = Math.min(1, maxSide / Math.max(w, h));
  return { width: Math.max(80, Math.round(w * k)), height: Math.max(80, Math.round(h * k)) };
}

/** Cuts the page out of the picture and straightens it. quad = four corners (0..1) tl, tr, br, bl. */
export function warpPerspective(img, quad, outW, outH) {
  const { data: s, width: sw, height: sh } = img;
  const src = quad.map(p => ({ x: p.x * sw, y: p.y * sh }));
  const H = homography([{ x: 0, y: 0 }, { x: 1, y: 0 }, { x: 1, y: 1 }, { x: 0, y: 1 }], src);
  const out = new Uint8ClampedArray(outW * outH * 4);
  for (let y = 0; y < outH; y++) {
    const ny = y / (outH - 1);
    for (let x = 0; x < outW; x++) {
      const nx = x / (outW - 1);
      const den = H[6] * nx + H[7] * ny + 1;
      const u = (H[0] * nx + H[1] * ny + H[2]) / den, v = (H[3] * nx + H[4] * ny + H[5]) / den;
      const x0 = Math.floor(u), y0 = Math.floor(v), fx = u - x0, fy = v - y0, o = (y * outW + x) * 4;
      if (x0 < 0 || y0 < 0 || x0 >= sw - 1 || y0 >= sh - 1) { out[o] = out[o + 1] = out[o + 2] = 255; out[o + 3] = 255; continue; }
      const i00 = (y0 * sw + x0) * 4, i10 = i00 + 4, i01 = i00 + sw * 4, i11 = i01 + 4;
      for (let c = 0; c < 3; c++) out[o + c] = s[i00 + c] * (1 - fx) * (1 - fy) + s[i10 + c] * fx * (1 - fy) + s[i01 + c] * (1 - fx) * fy + s[i11 + c] * fx * fy;
      out[o + 3] = 255;
    }
  }
  return { data: out, width: outW, height: outH };
}

/**
 * Cleans a straightened page. mode 'color': evens out light and shadows, whitens the paper, keeps colours (stamps, signatures).
 * mode 'bw': black text on white paper (best for printing and for small files).
 */
export function cleanPage(img, mode = 'color') {
  const { data, width: w, height: h } = img;
  const gray = toGray(img);
  // the paper's own brightness at every point: the biggest values in small blocks, smoothed
  const bs = Math.max(8, Math.round(Math.max(w, h) / 60)), bw = Math.ceil(w / bs), bh = Math.ceil(h / bs);
  const blocks = new Uint8ClampedArray(bw * bh);
  for (let by = 0; by < bh; by++) for (let bx = 0; bx < bw; bx++) {
    let max = 0;
    for (let y = by * bs; y < Math.min(h, (by + 1) * bs); y++) for (let x = bx * bs; x < Math.min(w, (bx + 1) * bs); x++) if (gray[y * w + x] > max) max = gray[y * w + x];
    blocks[by * bw + bx] = max;
  }
  const smooth = boxBlur(blocks, bw, bh, 2);
  const bg = new Float32Array(w * h);
  for (let y = 0; y < h; y++) {
    const fy = Math.min(bh - 1.001, Math.max(0, y / bs - 0.5)), y0 = Math.floor(fy), ty = fy - y0;
    for (let x = 0; x < w; x++) {
      const fx = Math.min(bw - 1.001, Math.max(0, x / bs - 0.5)), x0 = Math.floor(fx), tx = fx - x0;
      const a = smooth[y0 * bw + x0], b = smooth[y0 * bw + x0 + 1], c = smooth[(y0 + 1) * bw + x0], d = smooth[(y0 + 1) * bw + x0 + 1];
      bg[y * w + x] = Math.max(40, a * (1 - tx) * (1 - ty) + b * tx * (1 - ty) + c * (1 - tx) * ty + d * tx * ty);
    }
  }
  const out = new Uint8ClampedArray(w * h * 4);
  const flat = new Uint8ClampedArray(w * h);
  for (let i = 0; i < w * h; i++) flat[i] = clamp255((gray[i] / bg[i]) * 255);
  // black and white points from the flattened picture
  const hist = new Array(256).fill(0);
  for (let i = 0; i < flat.length; i++) hist[flat[i]]++;
  const at = f => { let s = 0; const goal = flat.length * f; for (let v = 0; v < 256; v++) { s += hist[v]; if (s >= goal) return v; } return 255; };
  const lo = at(0.02), hi = Math.max(lo + 40, at(0.6));
  if (mode === 'bw') {
    const mean = boxBlur(flat, w, h, Math.max(6, Math.round(Math.max(w, h) / 90)));
    for (let i = 0, p = 0; i < w * h; i++, p += 4) { const v = flat[i] > mean[i] * 0.88 + 6 ? 255 : 0; out[p] = out[p + 1] = out[p + 2] = v; out[p + 3] = 255; }
    return { data: out, width: w, height: h };
  }
  for (let i = 0, p = 0; i < w * h; i++, p += 4) {
    const k = 255 / bg[i];
    for (let c = 0; c < 3; c++) out[p + c] = clamp255(((data[p + c] * k - lo) / (hi - lo)) * 255);
    out[p + 3] = 255;
  }
  return { data: out, width: w, height: h };
}

/** Returns a smaller copy (for detection and previews). */
export function shrink(img, maxSide) {
  const k = Math.min(1, maxSide / Math.max(img.width, img.height));
  if (k >= 1) return img;
  const w = Math.max(1, Math.round(img.width * k)), h = Math.max(1, Math.round(img.height * k)), out = new Uint8ClampedArray(w * h * 4);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const sx = Math.min(img.width - 1, Math.floor((x + 0.5) / k)), sy = Math.min(img.height - 1, Math.floor((y + 0.5) / k)), si = (sy * img.width + sx) * 4, o = (y * w + x) * 4;
    out[o] = img.data[si]; out[o + 1] = img.data[si + 1]; out[o + 2] = img.data[si + 2]; out[o + 3] = 255;
  }
  return { data: out, width: w, height: h };
}
