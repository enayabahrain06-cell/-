import { useRef, useState, type DragEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { galleryApi, type AlbumDetail, type AlbumPhoto, type AlbumVisibility, type GalleryOptions } from '../../api/gallery'
import Icon from '../../components/Icon'
import { OrnamentDivider } from '../../components/ornaments'
import { Badge, buttonClass, EmptyCard, ErrorState, LoadingState, Modal, Notice, PrimaryButton, ROW_MAIN, SecondaryButton, Segmented, SURFACE, TextInput } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'
import AlbumFormModal from './AlbumFormModal'
import { Lightbox, PhotoGrid, VisibilityBadge } from './shared'
import { prepareImage } from './utils'

interface QueueItem { key: string; name: string; progress: number; error?: string; done?: boolean }

/** One album: photo grid and viewer; upload, arrange (order, cover, captions, delete) and sharing for those allowed. */
export default function AlbumPage() {
  const { id } = useParams()
  const albumId = Number(id)
  const { t, i18n } = useTranslation('gallery')
  const locale = i18n.language
  const qc = useQueryClient()
  const navigate = useNavigate()
  const opts = useQuery({ queryKey: ['gallery-options'], queryFn: galleryApi.options })
  const q = useQuery({ queryKey: ['gallery-album', albumId], queryFn: () => galleryApi.album(albumId), enabled: Number.isFinite(albumId) })
  const [viewer, setViewer] = useState<number | null>(null)
  const [arrange, setArrange] = useState(false)
  const [editing, setEditing] = useState(false)
  const [sharing, setSharing] = useState(false)
  const [captioning, setCaptioning] = useState<AlbumPhoto | null>(null)
  const [error, setError] = useState<string | null>(null)
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['gallery-album', albumId] })
    void qc.invalidateQueries({ queryKey: ['gallery-albums'] })
  }

  const setCover = useMutation({ mutationFn: (photoId: number) => galleryApi.update(albumId, { cover_photo_id: photoId }), onSuccess: refresh, onError: (e) => setError(parseApiError(e).message) })
  const remove = useMutation({ mutationFn: (photoId: number) => galleryApi.removePhoto(photoId), onSuccess: refresh, onError: (e) => setError(parseApiError(e).message) })
  const reorder = useMutation({
    mutationFn: (ids: number[]) => galleryApi.reorder(albumId, ids),
    onMutate: (ids) => {
      qc.setQueryData<AlbumDetail>(['gallery-album', albumId], (old) => old && { ...old, photos: ids.map((pid) => old.photos.find((p) => p.id === pid)!).filter(Boolean) })
    },
    onSettled: refresh,
  })
  const removeAlbum = useMutation({ mutationFn: () => galleryApi.remove(albumId), onSuccess: () => { void qc.invalidateQueries({ queryKey: ['gallery-albums'] }); navigate('/gallery') } })

  if (q.isLoading || opts.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState onRetry={() => void q.refetch()} />
  const album = q.data
  const photos = album.photos
  const move = (index: number, step: number) => {
    const to = index + step
    if (to < 0 || to >= photos.length) return
    const ids = photos.map((p) => p.id)
    ;[ids[index], ids[to]] = [ids[to], ids[index]]
    reorder.mutate(ids)
  }
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-5">
      <Link to="/gallery" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline"><Icon name="chevron" className="size-4 ltr:rotate-180" />{t('back')}</Link>

      <header className={`${SURFACE} p-4 sm:p-5`}>
        <div className="flex flex-wrap items-start gap-3">
          <div className={ROW_MAIN}>
            <div className="flex flex-wrap items-center gap-2">
              <h1 dir="auto" className="font-display text-3xl text-ink">{album.title}</h1>
              {album.visibility && <VisibilityBadge visibility={album.visibility} />}
              {album.allow_download && album.visibility !== 'staff' && <Badge tone="info">{t('download_allowed')}</Badge>}
            </div>
            <p className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-ink/60">
              {album.album_date && <span className="tabular-nums">{formatDate(album.album_date, locale)}</span>}
              {album.link && <span>{t(`link.${album.link.type}`)}: <bdi>{album.link.name}</bdi></span>}
              {album.term && <span>{album.term.name}</span>}
              <span className="tabular-nums">{t('photos_count', { count: album.photos_count, n: n(album.photos_count) })}</span>
            </p>
            {album.description && <p dir="auto" className="mt-2 whitespace-pre-line text-sm text-ink/75">{album.description}</p>}
          </div>
          <div className="flex flex-wrap gap-2">
            {(album.can.edit || photos.some((p) => p.can_delete)) && photos.length > 0 && (
              <SecondaryButton onClick={() => setArrange((v) => !v)} aria-pressed={arrange}><Icon name="sort" className="size-4" />{arrange ? t('arrange_done') : t('arrange')}</SecondaryButton>
            )}
            {album.can.edit && <SecondaryButton onClick={() => setEditing(true)}><Icon name="edit" className="size-4" />{t('edit_album')}</SecondaryButton>}
            {album.can.share && <SecondaryButton onClick={() => setSharing(true)}><Icon name="share" className="size-4" />{t('share')}</SecondaryButton>}
            {album.can.delete && (
              <SecondaryButton onClick={() => window.confirm(t('delete_album_confirm', { n: n(album.photos_count) })) && removeAlbum.mutate()} disabled={removeAlbum.isPending}>
                <Icon name="trash" className="size-4" />{t('delete_album')}
              </SecondaryButton>
            )}
          </div>
        </div>
        {album.created_by && (<><OrnamentDivider className="my-3 text-gold-500/70" /><p className="text-xs text-ink/50">{t('created_by', { name: album.created_by.name })}</p></>)}
      </header>

      {error && <Notice tone="error">{error}</Notice>}
      {album.can.upload && album.consent_withheld && album.consent_withheld.length > 0 && <ConsentWarning students={album.consent_withheld} />}
      {album.can.upload && opts.data && <Uploader albumId={album.id} options={opts.data} onUploaded={refresh} />}

      {photos.length === 0 ? <EmptyCard icon="camera" title={t('no_photos')} body={album.can.upload ? t('no_photos_upload') : undefined} /> : (
        <PhotoGrid photos={photos} label={t('photos')} onOpen={(i) => (arrange ? undefined : setViewer(i))}
          renderTools={(p, i) => (
            <PhotoTools photo={p} index={i} last={photos.length - 1} arrange={arrange} canEdit={album.can.edit} isCover={(album.cover_photo_id ?? photos[0]?.id) === p.id}
              onMove={move} onCover={() => setCover.mutate(p.id)} onCaption={() => setCaptioning(p)}
              onDelete={() => window.confirm(t('delete_photo_confirm')) && remove.mutate(p.id)} />
          )} />
      )}

      {viewer !== null && <Lightbox album={album} photos={photos} index={viewer} onIndex={setViewer} onClose={() => setViewer(null)} />}
      {editing && opts.data && <AlbumFormModal options={opts.data} album={album} onClose={() => setEditing(false)} />}
      {sharing && <ShareModal album={album} onClose={() => setSharing(false)} onSaved={refresh} />}
      {captioning && <CaptionModal photo={captioning} onClose={() => setCaptioning(null)} onSaved={refresh} />}
    </div>
  )
}

/** Per-photo actions in manage mode: order, cover and caption (album editors), delete (own uploads for teachers). */
function PhotoTools({ photo, index, last, arrange, canEdit, isCover, onMove, onCover, onCaption, onDelete }: {
  photo: AlbumPhoto; index: number; last: number; arrange: boolean; canEdit: boolean; isCover: boolean
  onMove: (i: number, step: number) => void; onCover: () => void; onCaption: () => void; onDelete: () => void
}) {
  const { t } = useTranslation('gallery')
  const tool = 'inline-grid size-8 place-items-center rounded-lg bg-white/95 text-ink shadow-sm hover:bg-white disabled:opacity-40'
  return (
    <>
      {isCover && <span className="pointer-events-none absolute start-1 top-1 rounded-full bg-deep/85 px-2 py-0.5 text-xs font-medium text-gold-300">{t('cover')}</span>}
      {arrange ? (
        <div className="absolute inset-x-1 bottom-1 flex flex-wrap justify-center gap-1">
          {canEdit && <button type="button" className={tool} onClick={() => onMove(index, -1)} disabled={index === 0} aria-label={t('move_earlier')}><Icon name="chevron" className="size-4 ltr:rotate-180" /></button>}
          {canEdit && <button type="button" className={tool} onClick={() => onMove(index, 1)} disabled={index === last} aria-label={t('move_later')}><Icon name="chevron" className="size-4 rtl:rotate-180" /></button>}
          {canEdit && !isCover && <button type="button" className={tool} onClick={onCover} aria-label={t('set_cover')}><Icon name="flag" className="size-4" /></button>}
          {canEdit && <button type="button" className={tool} onClick={onCaption} aria-label={t('caption')}><Icon name="edit" className="size-4" /></button>}
          {photo.can_delete && <button type="button" className={`${tool} text-danger`} onClick={onDelete} aria-label={t('delete_photo')}><Icon name="trash" className="size-4" /></button>}
        </div>
      ) : null}
    </>
  )
}

function ConsentWarning({ students }: { students: { id: number; full_name: string; student_no: string }[] }) {
  const { t, i18n } = useTranslation('gallery')
  return (
    <div role="alert" className="rounded-xl border border-gold-500/40 bg-gold-500/10 px-4 py-3 text-sm text-ink">
      <p className="flex items-center gap-2 font-semibold"><Icon name="alert" className="size-4 text-gold-700" />{t('consent_title', { n: formatNumber(students.length, i18n.language) })}</p>
      <p className="mt-1 text-ink/70">{t('consent_body')}</p>
      <ul className="mt-2 flex flex-wrap gap-1.5">
        {students.map((s) => <li key={s.id}><Link to={`/students/${s.id}`} className="inline-flex rounded-full bg-white px-2.5 py-0.5 text-xs font-medium text-ink/80 ring-1 ring-ink/10 hover:bg-ink/5"><bdi>{s.full_name}</bdi></Link></li>)}
      </ul>
    </div>
  )
}

/**
 * Drag and drop (desktop), pick several files, or take a photo with the phone camera. Three uploads run at a time;
 * each shows its progress, and a refused file shows the server's reason without stopping the others.
 */
function Uploader({ albumId, options: o, onUploaded }: { albumId: number; options: GalleryOptions; onUploaded: () => void }) {
  const { t, i18n } = useTranslation('gallery')
  const num = (v: number) => formatNumber(v, i18n.language)
  const [queue, setQueue] = useState<QueueItem[]>([])
  const [over, setOver] = useState(false)
  const pick = useRef<HTMLInputElement>(null)
  const camera = useRef<HTMLInputElement>(null)
  const accept = o.video_enabled ? 'image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/webm' : 'image/jpeg,image/png,image/webp,image/heic,image/heif'
  const busy = queue.some((x) => !x.done && !x.error)

  const patch = (key: string, p: Partial<QueueItem>) => setQueue((qs) => qs.map((x) => (x.key === key ? { ...x, ...p } : x)))

  const start = async (files: File[]) => {
    const accepted = files.filter((f) => f.type.startsWith('image/') || (o.video_enabled && f.type.startsWith('video/')))
    const items = accepted.map((f, i) => ({ key: `${Date.now()}-${i}-${f.name}`, name: f.name, progress: 0, file: f }))
    const rejected = files.length - accepted.length
    setQueue((qs) => [...qs.filter((x) => !x.done), ...items.map(({ file: _f, ...x }) => x),
      ...(rejected > 0 ? [{ key: `rej-${Date.now()}`, name: t('skipped_files', { n: num(rejected) }), progress: 0, error: o.video_enabled ? t('only_media') : t('only_photos') }] : [])])
    let next = 0
    const worker = async () => {
      while (next < items.length) {
        const item = items[next++]
        try {
          const isVideo = item.file.type.startsWith('video/')
          const maxMb = isVideo ? o.video_max_mb : o.max_upload_mb
          const blob = isVideo ? item.file : await prepareImage(item.file)
          if (blob.size > maxMb * 1024 * 1024) throw new Error(t('too_large', { max: num(maxMb) }))
          const name = blob === item.file ? item.file.name : item.file.name.replace(/\.\w+$/, '') + '.jpg'
          await galleryApi.upload(albumId, blob, name, (f) => patch(item.key, { progress: f }))
          patch(item.key, { done: true, progress: 1 })
        } catch (e) {
          patch(item.key, { error: e instanceof Error && !('isAxiosError' in e) ? e.message : parseApiError(e).message })
        }
      }
    }
    await Promise.all([worker(), worker(), worker()])
    onUploaded()
  }

  const onDrop = (e: DragEvent) => {
    e.preventDefault()
    setOver(false)
    void start(Array.from(e.dataTransfer.files))
  }

  return (
    <section className={`${SURFACE} space-y-3 p-4`} aria-label={t('upload')}>
      <div onDragOver={(e) => { e.preventDefault(); setOver(true) }} onDragLeave={() => setOver(false)} onDrop={onDrop}
        className={`grid place-items-center gap-3 rounded-xl border-2 border-dashed px-4 py-6 text-center transition ${over ? 'border-brand-500 bg-brand-50' : 'border-ink/15'}`}>
        <Icon name="camera" className="size-8 text-brand-700" />
        <p className="text-sm text-ink/70">
          <span className="hidden lg:inline">{t('drop_here')} </span>
          {o.video_enabled ? t('limits_video', { photo: num(o.max_upload_mb), video: num(o.video_max_mb), seconds: num(o.video_max_seconds) }) : t('limits', { photo: num(o.max_upload_mb) })}
        </p>
        <div className="flex flex-wrap justify-center gap-2">
          <button type="button" onClick={() => pick.current?.click()} className={buttonClass('primary')}><Icon name="plus" className="size-4" />{t('choose_files')}</button>
          <button type="button" onClick={() => camera.current?.click()} className={buttonClass('secondary', 'lg:hidden')}><Icon name="camera" className="size-4" />{t('take_photo')}</button>
        </div>
        <input ref={pick} type="file" accept={accept} multiple hidden onChange={(e) => { void start(Array.from(e.target.files ?? [])); e.target.value = '' }} />
        <input ref={camera} type="file" accept="image/*" capture="environment" hidden onChange={(e) => { void start(Array.from(e.target.files ?? [])); e.target.value = '' }} />
      </div>
      {queue.length > 0 && (
        <ul className="space-y-1.5" aria-live="polite" aria-busy={busy}>
          {queue.map((x) => (
            <li key={x.key} className="flex items-center gap-3 text-sm">
              <span dir="auto" className="min-w-0 flex-1 truncate text-ink/75">{x.name}</span>
              {x.error ? <span className="max-w-[60%] text-end text-xs text-danger">{x.error}</span>
                : x.done ? <Icon name="check" className="size-4 text-brand-700" />
                : <span className="h-1.5 w-24 overflow-hidden rounded-full bg-ink/8"><span className="block h-full bg-brand-600 transition-[width]" style={{ width: `${Math.round(x.progress * 100)}%` }} /></span>}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

/** Visibility, السماح بالتحميل and the optional WhatsApp notice (managers only). */
function ShareModal({ album, onClose, onSaved }: { album: AlbumDetail; onClose: () => void; onSaved: () => void }) {
  const { t, i18n } = useTranslation('gallery')
  const [visibility, setVisibility] = useState<AlbumVisibility>(album.visibility ?? 'staff')
  const [download, setDownload] = useState(album.allow_download)
  const [notify, setNotify] = useState(false)
  const [done, setDone] = useState<number | null>(null)
  const save = useMutation({
    mutationFn: () => galleryApi.share(album.id, { visibility, allow_download: download, notify: notify && visibility !== 'staff' }),
    onSuccess: (r) => { onSaved(); if (notify && visibility !== 'staff') setDone(r.notified); else onClose() },
  })
  const err = save.error ? parseApiError(save.error).message : null
  const options = (['staff', 'linked', 'all_guardians'] as const).filter((v) => v !== 'linked' || album.link)

  return (
    <Modal title={t('share_title')} onClose={onClose}
      footer={done !== null ? <PrimaryButton type="button" onClick={onClose}>{t('common:close')}</PrimaryButton> : <>
        <SecondaryButton type="button" onClick={onClose}>{t('cancel')}</SecondaryButton>
        <PrimaryButton type="button" onClick={() => save.mutate()} loading={save.isPending}>{t('save')}</PrimaryButton>
      </>}>
      {done !== null ? <Notice>{t('notified', { n: formatNumber(done, i18n.language) })}</Notice> : (
        <>
          {err && <Notice tone="error">{err}</Notice>}
          <Segmented name="album-visibility" label={t('visibility_label')} value={visibility} onChange={setVisibility} fill
            options={options.map((v) => ({ value: v, label: t(`visibility.${v}`) }))} />
          <p className="text-sm text-ink/65">{t(`visibility_help.${visibility}`, { link: album.link?.name ?? '' })}</p>
          {visibility !== 'staff' && (
            <div className="space-y-3">
              <label className="flex items-start gap-2 text-sm text-ink/80">
                <input type="checkbox" className="mt-0.5 size-4 accent-brand-700" checked={download} onChange={(e) => setDownload(e.target.checked)} />
                <span><span className="font-medium">{t('allow_download')}</span><span className="block text-xs text-ink/55">{t('allow_download_help')}</span></span>
              </label>
              <label className="flex items-start gap-2 text-sm text-ink/80">
                <input type="checkbox" className="mt-0.5 size-4 accent-brand-700" checked={notify} onChange={(e) => setNotify(e.target.checked)} />
                <span><span className="font-medium">{t('notify')}</span><span className="block text-xs text-ink/55">{t('notify_help')}</span></span>
              </label>
            </div>
          )}
          {album.consent_withheld && album.consent_withheld.length > 0 && visibility !== 'staff' && <Notice tone="info">{t('consent_share', { n: formatNumber(album.consent_withheld.length, i18n.language) })}</Notice>}
        </>
      )}
    </Modal>
  )
}

function CaptionModal({ photo, onClose, onSaved }: { photo: AlbumPhoto; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation('gallery')
  const [caption, setCaption] = useState(photo.caption ?? '')
  const save = useMutation({ mutationFn: () => galleryApi.caption(photo.id, caption.trim() || null), onSuccess: () => { onSaved(); onClose() } })
  return (
    <Modal title={t('caption')} onClose={onClose}
      footer={<>
        <SecondaryButton type="button" onClick={onClose}>{t('cancel')}</SecondaryButton>
        <PrimaryButton type="button" onClick={() => save.mutate()} loading={save.isPending}>{t('save')}</PrimaryButton>
      </>}>
      {save.error && <Notice tone="error">{parseApiError(save.error).message}</Notice>}
      <TextInput label={t('caption')} value={caption} onChange={(e) => setCaption(e.target.value)} maxLength={500} dir="auto" />
    </Modal>
  )
}
