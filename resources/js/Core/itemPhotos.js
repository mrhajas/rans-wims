/** The photos of an item in the order the viewer walks through them: the face first, then the other photos (up to three in all). */
export function itemPhotoList(item) {
  const out = [];
  if (item && item.photo) out.push({ url: item.photo });
  ((item && item.photos) || []).forEach(p => out.push({ url: p.url, id: p.id }));

  return out;
}
