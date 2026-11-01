// A photo of a person for a staff or user record: the background is removed (on this device, nothing is sent anywhere), the person is put on plain white, the picture is made
// small (at most `maxSide` pixels on the longest side) and saved as a JPEG of about 40-90 KB. If the background cannot be removed (the cut-out engine could not load) the photo is
// still put on white as taken and made small, and `cut` is false so the screen can say so.
import { removeBackground } from '@/Core/removeBackground';

/** @param {Blob|File} file @param {(s:string)=>void} [onStatus] @param {{maxSide?:number, quality?:number}} [opts] @returns {Promise<{file: File, cut: boolean}>} */
export async function makePortrait(file, onStatus, { maxSide = 800, quality = 0.82 } = {}) {
  let src = file;
  let cut = true;
  try {
    src = await removeBackground(file, onStatus, { trim: false });
  } catch (e) {
    cut = false;
    src = file;
  }
  onStatus && onStatus('Making the photo small');
  const bmp = await createImageBitmap(src, { imageOrientation: 'from-image' });
  const scale = Math.min(1, maxSide / Math.max(bmp.width, bmp.height));
  const w = Math.max(1, Math.round(bmp.width * scale));
  const h = Math.max(1, Math.round(bmp.height * scale));
  const cv = document.createElement('canvas');
  cv.width = w;
  cv.height = h;
  const ctx = cv.getContext('2d');
  ctx.fillStyle = '#ffffff'; // plain white behind the person
  ctx.fillRect(0, 0, w, h);
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(bmp, 0, 0, w, h);
  bmp.close && bmp.close();
  const blob = await new Promise(r => cv.toBlob(r, 'image/jpeg', quality));
  if (!blob) throw new Error('The photo could not be saved.');

  return { file: new File([blob], 'photo-' + Date.now() + '.jpg', { type: 'image/jpeg' }), cut };
}
