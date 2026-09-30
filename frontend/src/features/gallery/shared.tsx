import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { galleryFileUrl, type Album, type AlbumPhoto } from '../../api/gallery'
import Icon from '../../components/Icon'
import { StarSpinner } from '../../components/ornaments'
import { Badge } from '../../components/ui'
import { downloadAuthFile, useAuthBlob } from '../../lib/authBlob'
import { formatDate, formatNumber } from '../../lib/format'
import { useScrollLock } from '../../components/useScrollLock'

/** A gallery image loaded with the session token. Keeps its box (aspect ratio) while loading, so nothing shifts. */
export function AuthImage({ photoId, variant = 'thumb', alt, className = '' }: { photoId: number; variant?: 'thumb' | 'image'; alt: string; className?: string }) {
  const { src, failed } = useAuthBlob(galleryFileUrl(photoId, variant))
  if (failed) return <span className={`grid place-items-center bg-ink/6 text-ink/40 ${className}`}><Icon name="camera" className="size-6" /></span>
  if (!src) return <span className={`block animate-pulse bg-ink/6 ${className}`} aria-hidden />
  return <img src={src} alt={alt} className={`object-cover ${className}`} draggable={false} />
}

/** Album tile for the album grid (staff and family). */
export function AlbumCard({ album, href, onOpen }: { album: Album; href?: string; onOpen?: () => void }) {
  const { t, i18n } = useTranslation('gallery')
  const locale = i18n.language
  const body = (
    <>
      <span className="relative block aspect-[4/3] overflow-hidden rounded-t-2xl bg-ink/5">
        {album.cover ? <AuthImage photoId={album.cover.id} alt="" className="size-full" /> : (
          <span className="grid size-full place-items-center text-ink/30"><Icon name="camera" className="size-10" /></span>
        )}
        <span className="absolute bottom-2 end-2 rounded-full bg-ink/70 px-2 py-0.5 text-xs tabular-nums text-white">
          {t('photos_count', { count: album.photos_count, n: formatNumber(album.photos_count, locale) })}
        </span>
      </span>
      <span className="block space-y-1.5 p-4">
        <span dir="auto" className="line-clamp-2 block text-base font-semibold text-ink">{album.title}</span>
        <span className="flex flex-wrap items-center gap-1.5 text-xs text-ink/55">
          {album.album_date && <span className="tabular-nums">{formatDate(album.album_date, locale)}</span>}
          {album.link?.name && <Badge tone="info"><bdi>{album.link.name}</bdi></Badge>}
          {album.visibility && <VisibilityBadge visibility={album.visibility} />}
        </span>
      </span>
    </>
  )
  const cls = 'block w-full rounded-2xl border border-ink/8 bg-white text-start shadow-sm transition hover:border-brand-600/30 hover:shadow-md'
  return href ? <a href={href} onClick={(e) => { if (onOpen) { e.preventDefault(); onOpen() } }} className={cls}>{body}</a>
    : <button type="button" onClick={onOpen} className={cls}>{body}</button>
}

export function VisibilityBadge({ visibility }: { visibility: NonNullable<Album['visibility']> }) {
  const { t } = useTranslation('gallery')
  return <Badge tone={visibility === 'staff' ? 'muted' : 'gold'}>{t(`visibility.${visibility}`)}</Badge>
}

/** Square photo grid; each tile opens the viewer. `renderTools` adds per-photo actions (staff). */
export function PhotoGrid({ photos, onOpen, renderTools, label }: { photos: AlbumPhoto[]; onOpen: (index: number) => void; renderTools?: (p: AlbumPhoto, i: number) => ReactNode; label: string }) {
  const { t } = useTranslation('gallery')
  return (
    <ul className="grid grid-cols-3 gap-1.5 sm:grid-cols-4 sm:gap-2 lg:grid-cols-5 xl:grid-cols-6" aria-label={label}>
      {photos.map((p, i) => (
        <li key={p.id} className="group relative">
          <button type="button" onClick={() => onOpen(i)} className="relative block aspect-square w-full overflow-hidden rounded-lg bg-ink/5"
            aria-label={p.caption || t('photo_n', { n: i + 1 })}>
            <AuthImage photoId={p.id} alt={p.caption ?? ''} className="size-full transition group-hover:scale-[1.02]" />
            {p.kind === 'video' && (
              <span className="absolute inset-0 grid place-items-center"><span className="grid size-10 place-items-center rounded-full bg-ink/60 text-white"><PlayIcon /></span></span>
            )}
          </button>
          {renderTools?.(p, i)}
        </li>
      ))}
    </ul>
  )
}

function PlayIcon() {
  return <svg viewBox="0 0 24 24" className="size-5" fill="currentColor" aria-hidden><path d="M8 5.5v13l11-6.5z" /></svg>
}

/**
 * Full-screen viewer: arrows and ←/→ keys, swipe on touch screens, Esc closes. Download shows only when the album
 * allows this user to download (the server checks it again).
 */
export function Lightbox({ album, photos, index, onIndex, onClose }: { album: Pick<Album, 'id' | 'title' | 'can'>; photos: AlbumPhoto[]; index: number; onIndex: (i: number) => void; onClose: () => void }) {
  const { t, i18n } = useTranslation('gallery')
  const rtl = i18n.dir() === 'rtl'
  const photo = photos[index]
  const [busy, setBusy] = useState(false)
  const touch = useRef<{ x: number; y: number } | null>(null)
  useScrollLock(true)

  const go = useCallback((step: number) => {
    if (photos.length === 0) return
    onIndex((index + step + photos.length) % photos.length)
  }, [index, photos.length, onIndex])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
      // Visual direction: the right arrow moves right, which is "previous" in Arabic.
      if (e.key === 'ArrowRight') go(rtl ? -1 : 1)
      if (e.key === 'ArrowLeft') go(rtl ? 1 : -1)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [go, onClose, rtl])

  if (!photo) return null
  const download = async () => {
    setBusy(true)
    try {
      await downloadAuthFile(galleryFileUrl(photo.id, photo.kind === 'video' ? 'video' : 'image'), `album-${album.id}-${photo.id}`)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[60] flex flex-col bg-ink/95 text-white" role="dialog" aria-modal="true" aria-label={album.title}
      onTouchStart={(e) => { touch.current = { x: e.touches[0].clientX, y: e.touches[0].clientY } }}
      onTouchEnd={(e) => {
        const s = touch.current
        touch.current = null
        if (!s) return
        const dx = e.changedTouches[0].clientX - s.x
        const dy = e.changedTouches[0].clientY - s.y
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) go((dx < 0) !== rtl ? 1 : -1)
      }}>
      <div className="flex shrink-0 items-center gap-2 px-3 py-2 pt-[max(0.5rem,env(safe-area-inset-top))]">
        <span className="min-w-0 flex-1 truncate text-sm text-white/80"><bdi>{album.title}</bdi> · <span className="tabular-nums">{formatNumber(index + 1, i18n.language)} / {formatNumber(photos.length, i18n.language)}</span></span>
        {album.can.download && (
          <button type="button" onClick={() => void download()} disabled={busy} className="inline-flex min-h-10 items-center gap-1.5 rounded-lg px-3 text-sm hover:bg-white/10 disabled:opacity-60">
            {busy ? <StarSpinner className="size-4" label={t('downloading')} /> : <Icon name="download" className="size-5" />}
            <span className="hidden sm:inline">{t('download')}</span>
          </button>
        )}
        <button type="button" onClick={onClose} className="inline-grid size-10 place-items-center rounded-lg hover:bg-white/10" aria-label={t('common:close')}><Icon name="close" className="size-6" /></button>
      </div>
      <div className="relative flex min-h-0 flex-1 items-center justify-center px-2 pb-2">
        {photo.kind === 'video' ? <LightboxVideo key={photo.id} photo={photo} /> : <LightboxImage key={photo.id} photo={photo} />}
        {photos.length > 1 && (
          <>
            <button type="button" onClick={() => go(-1)} aria-label={t('previous')} className="absolute start-2 top-1/2 hidden size-12 -translate-y-1/2 place-items-center rounded-full bg-white/10 hover:bg-white/20 sm:grid">
              <Icon name="chevron" className="size-6 ltr:rotate-180" />
            </button>
            <button type="button" onClick={() => go(1)} aria-label={t('next')} className="absolute end-2 top-1/2 hidden size-12 -translate-y-1/2 place-items-center rounded-full bg-white/10 hover:bg-white/20 sm:grid">
              <Icon name="chevron" className="size-6 rtl:rotate-180" />
            </button>
          </>
        )}
      </div>
      {photo.caption && <p dir="auto" className="shrink-0 px-4 pb-[max(1rem,env(safe-area-inset-bottom))] text-center text-sm text-white/85">{photo.caption}</p>}
    </div>
  )
}

function LightboxImage({ photo }: { photo: AlbumPhoto }) {
  const { t } = useTranslation('gallery')
  const large = useAuthBlob(galleryFileUrl(photo.id, 'image'))
  const thumb = useAuthBlob(large.src ? null : galleryFileUrl(photo.id, 'thumb'))
  const src = large.src ?? thumb.src
  if (large.failed) return <p className="text-sm text-white/70">{t('load_failed')}</p>
  if (!src) return <StarSpinner className="size-10 text-white/70" label={t('common:loading')} />
  return <img src={src} alt={photo.caption ?? ''} className="max-h-full max-w-full select-none object-contain" draggable={false} />
}

function LightboxVideo({ photo }: { photo: AlbumPhoto }) {
  const { t } = useTranslation('gallery')
  const poster = useAuthBlob(galleryFileUrl(photo.id, 'image'))
  const video = useAuthBlob(galleryFileUrl(photo.id, 'video'))
  if (video.failed) return <p className="text-sm text-white/70">{t('load_failed')}</p>
  if (!video.src) return <StarSpinner className="size-10 text-white/70" label={t('common:loading')} />
  return <video src={video.src} poster={poster.src ?? undefined} controls playsInline className="max-h-full max-w-full" />
}
