// Cuts the object out of a photo and drops the background, entirely inside the browser (the picture never leaves the device): the U-2-Net "small" model
// (Apache-2.0, 4.6 MB, public/models/u2netp.onnx) finds the main object, onnxruntime-web (MIT, public/vendor/ort) runs it. The result is a PNG with a transparent
// background, trimmed to the object. It loads only when somebody asks for it, and the model and engine are cached by the browser after the first use.
const MODEL = '/models/u2netp.onnx';
const SIZE = 320; // the model's input size
const MAX_PX = 1800; // the longest side of the finished picture

let sessionPromise = null;
let ort = null;

async function session(onStatus) {
  if (!sessionPromise) {
    sessionPromise = (async () => {
      onStatus && onStatus('Loading the cut-out engine');
      ort = await import('onnxruntime-web/wasm');
      ort.env.wasm.wasmPaths = '/vendor/ort/';
      ort.env.wasm.numThreads = 1; // one thread needs no special server headers
      return ort.InferenceSession.create(MODEL, { executionProviders: ['wasm'], graphOptimizationLevel: 'all' });
    })().catch(e => {
      sessionPromise = null;
      throw e;
    });
  }

  return sessionPromise;
}

async function load(file) {
  // createImageBitmap honours the photo's rotation
  const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
  const scale = Math.min(1, MAX_PX / Math.max(bmp.width, bmp.height));
  const w = Math.max(1, Math.round(bmp.width * scale));
  const h = Math.max(1, Math.round(bmp.height * scale));
  const cv = document.createElement('canvas');
  cv.width = w;
  cv.height = h;
  cv.getContext('2d').drawImage(bmp, 0, 0, w, h);
  bmp.close && bmp.close();

  return cv;
}

const smooth = (a, b, x) => {
  const t = Math.min(1, Math.max(0, (x - a) / (b - a)));

  return t * t * (3 - 2 * t);
};

/** @param {Blob|File} file  @param {{trim?:boolean}} opts trim:false keeps the whole picture (the person stays where they are in the frame)  @returns {Promise<Blob>} PNG with a transparent background */
export async function removeBackground(file, onStatus, opts = {}) {
  const s = await session(onStatus);
  onStatus && onStatus('Finding the object');
  const src = await load(file);

  // the picture as the model wants it: 320 x 320, scaled to 0..1, colours centred, channels first
  const small = document.createElement('canvas');
  small.width = small.height = SIZE;
  small.getContext('2d').drawImage(src, 0, 0, SIZE, SIZE);
  const px = small.getContext('2d').getImageData(0, 0, SIZE, SIZE).data;
  let max = 1;
  for (let i = 0; i < px.length; i += 4) max = Math.max(max, px[i], px[i + 1], px[i + 2]);
  const input = new Float32Array(3 * SIZE * SIZE);
  const mean = [0.485, 0.456, 0.406];
  const std = [0.229, 0.224, 0.225];
  for (let i = 0, p = 0; i < px.length; i += 4, p++) {
    for (let c = 0; c < 3; c++) input[c * SIZE * SIZE + p] = (px[i + c] / max - mean[c]) / std[c];
  }

  const out = await s.run({ [s.inputNames[0]]: new ort.Tensor('float32', input, [1, 3, SIZE, SIZE]) });
  const raw = out[s.outputNames[0]].data;
  let lo = Infinity;
  let hi = -Infinity;
  for (let i = 0; i < raw.length; i++) {
    lo = Math.min(lo, raw[i]);
    hi = Math.max(hi, raw[i]);
  }
  const maskSmall = document.createElement('canvas');
  maskSmall.width = maskSmall.height = SIZE;
  const mctx = maskSmall.getContext('2d');
  const md = mctx.createImageData(SIZE, SIZE);
  for (let i = 0; i < raw.length; i++) {
    const v = Math.round(smooth(0.18, 0.82, (raw[i] - lo) / (hi - lo || 1)) * 255); // a crisper edge than the raw blur
    md.data[i * 4] = md.data[i * 4 + 1] = md.data[i * 4 + 2] = 255;
    md.data[i * 4 + 3] = v;
  }
  mctx.putImageData(md, 0, 0);

  // the mask, stretched to the picture's size (smooth), becomes its transparency
  onStatus && onStatus('Cutting it out');
  const w = src.width;
  const h = src.height;
  const mask = document.createElement('canvas');
  mask.width = w;
  mask.height = h;
  const mc = mask.getContext('2d');
  mc.imageSmoothingEnabled = true;
  mc.imageSmoothingQuality = 'high';
  mc.drawImage(maskSmall, 0, 0, w, h);
  const alpha = mc.getImageData(0, 0, w, h).data;
  const ctx = src.getContext('2d');
  const img = ctx.getImageData(0, 0, w, h);
  let x0 = w, y0 = h, x1 = 0, y1 = 0;
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const a = alpha[(y * w + x) * 4 + 3];
      img.data[(y * w + x) * 4 + 3] = a;
      if (a > 128) {
        if (x < x0) x0 = x;
        if (x > x1) x1 = x;
        if (y < y0) y0 = y;
        if (y > y1) y1 = y;
      }
    }
  }
  if (x1 <= x0 || y1 <= y0) throw new Error('No object was found in this photo.');
  ctx.putImageData(img, 0, 0);
  if (opts.trim === false) return new Promise((resolve, reject) => src.toBlob(b => (b ? resolve(b) : reject(new Error('Could not save the picture.'))), 'image/png'));

  // keep just the object: crop to it with a small margin
  const pad = Math.round(Math.max(x1 - x0, y1 - y0) * 0.04);
  const cx = Math.max(0, x0 - pad);
  const cy = Math.max(0, y0 - pad);
  const cw = Math.min(w, x1 + pad) - cx;
  const ch = Math.min(h, y1 + pad) - cy;
  const outCv = document.createElement('canvas');
  outCv.width = cw;
  outCv.height = ch;
  outCv.getContext('2d').drawImage(src, cx, cy, cw, ch, 0, 0, cw, ch);

  return new Promise((resolve, reject) => outCv.toBlob(b => (b ? resolve(b) : reject(new Error('Could not save the picture.'))), 'image/png'));
}
