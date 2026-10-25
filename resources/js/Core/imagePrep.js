// Makes a photo small BEFORE it is sent (on the person's own device), so saving an item with photos is quick even on a slow line: at most 1600 px on the longest side,
// turned the right way round, JPEG (or WebP when the picture has a transparent background, as a cut-out does). A 6 MB camera photo becomes about 250 KB.
const MAX = 1600;

function hasAlpha(canvas) {
  const s = document.createElement('canvas');
  s.width = s.height = 32;
  const c = s.getContext('2d');
  c.drawImage(canvas, 0, 0, 32, 32);
  const d = c.getImageData(0, 0, 32, 32).data;
  for (let i = 3; i < d.length; i += 4) if (d[i] < 250) return true;

  return false;
}

/** @returns {Promise<File>} the smaller picture, or the same file when it cannot be made smaller */
export async function prepareImage(file, { maxSide = MAX, quality = 0.86 } = {}) {
  if (!file || !file.type || !file.type.startsWith('image/') || /svg|gif/.test(file.type)) return file;
  let bmp;
  try {
    bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
  } catch (e) {
    return file;
  }
  const scale = Math.min(1, maxSide / Math.max(bmp.width, bmp.height));
  const w = Math.max(1, Math.round(bmp.width * scale));
  const h = Math.max(1, Math.round(bmp.height * scale));
  const cv = document.createElement('canvas');
  cv.width = w;
  cv.height = h;
  const ctx = cv.getContext('2d');
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(bmp, 0, 0, w, h);
  bmp.close && bmp.close();
  const alpha = /png|webp/.test(file.type) && hasAlpha(cv);
  if (!alpha) {
    // a JPEG has no transparency: put the picture on white
    const flat = document.createElement('canvas');
    flat.width = w;
    flat.height = h;
    const f = flat.getContext('2d');
    f.fillStyle = '#fff';
    f.fillRect(0, 0, w, h);
    f.drawImage(cv, 0, 0);
    cv.width = w; // reuse
    cv.getContext('2d').drawImage(flat, 0, 0);
  }
  const type = alpha ? 'image/webp' : 'image/jpeg';
  const blob = await new Promise(r => cv.toBlob(r, type, alpha ? 0.9 : quality));
  if (!blob || (scale >= 1 && blob.size >= file.size && !alpha)) return file;
  const base = String(file.name || 'photo').replace(/\.[^.]+$/, '');

  return new File([blob], base + (alpha ? '.webp' : '.jpg'), { type });
}
