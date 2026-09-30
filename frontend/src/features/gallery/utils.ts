import type { AlbumLinkType, GalleryOptions } from '../../api/gallery'

/** Options of the link picker for one link type. */
export function linkOptions(o: GalleryOptions, type: AlbumLinkType) {
  if (type === 'activity') return o.activities.map((a) => ({ value: String(a.id), label: a.name, group: a.type }))
  const rows = type === 'lesson' ? o.classes : type === 'level' ? o.levels : o.competitions
  return rows.map((r) => ({ value: String(r.id), label: r.name }))
}

/**
 * Before upload: phones shoot 12–48 MP photos. Large JPEG/PNG/WebP files are scaled to 2560 px and re-encoded in
 * the browser, so uploads are fast on mobile data; the server still does the final resize and strips EXIF. Files
 * the browser cannot decode (HEIC on desktop) go up unchanged and the server answers for them.
 */
export async function prepareImage(file: File): Promise<Blob> {
  const MAX = 2560
  if (!/^image\/(jpeg|png|webp)$/.test(file.type) || typeof createImageBitmap !== 'function') return file
  try {
    const bitmap = await createImageBitmap(file)
    const scale = Math.min(1, MAX / Math.max(bitmap.width, bitmap.height))
    if (scale === 1 && file.size < 3 * 1024 * 1024) {
      bitmap.close()
      return file
    }
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)
    canvas.getContext('2d')?.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    bitmap.close()
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9))
    return blob && blob.size < file.size ? blob : file
  } catch {
    return file
  }
}
