import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { familyGalleryApi } from '../../api/gallery'
import { MEmpty, MListSkeleton } from '../../components/mobile/atoms'
import { formatDate } from '../../lib/format'
import { AlbumCard, Lightbox, PhotoGrid } from '../gallery/shared'
import PortalLayout from './PortalLayout'
import { PortalError } from './shared'

/** الصور (/my-gallery): albums the centre shared with this family. Photos only view; download when the album allows. */
export function FamilyGalleryPage() {
  const { t, i18n } = useTranslation('gallery')
  const navigate = useNavigate()
  const q = useQuery({ queryKey: ['portal-gallery', i18n.language], queryFn: familyGalleryApi.albums })
  const title = t('portal.title')

  return (
    <PortalLayout title={title} back="/my-account" breadcrumb={[{ label: t('portal:tabs.account'), to: '/my-account' }, { label: title }]}>
      <div className="space-y-4">
        <p className="text-[13px] text-ink/65">{t('portal.intro')}</p>
        {q.isError ? <PortalError onRetry={() => void q.refetch()} /> : q.isLoading ? <MListSkeleton rows={3} /> : (q.data ?? []).length === 0 ? (
          <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty icon="camera" text={t('portal.empty')} /></div>
        ) : (
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-label={title}>
            {q.data!.map((a) => <li key={a.id}><AlbumCard album={a} href={`/my-gallery/${a.id}`} onOpen={() => navigate(`/my-gallery/${a.id}`)} /></li>)}
          </ul>
        )}
      </div>
    </PortalLayout>
  )
}

export function FamilyAlbumPage() {
  const { id } = useParams()
  const { t, i18n } = useTranslation('gallery')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['portal-album', Number(id), locale], queryFn: () => familyGalleryApi.album(Number(id)) })
  const [viewer, setViewer] = useState<number | null>(null)
  const album = q.data
  const title = album?.title ?? t('portal.title')

  return (
    <PortalLayout title={title} back="/my-gallery" breadcrumb={[{ label: t('portal.title'), to: '/my-gallery' }, { label: title }]}>
      {q.isError ? <PortalError onRetry={() => void q.refetch()} /> : q.isLoading || !album ? <MListSkeleton rows={3} /> : (
        <div className="space-y-4">
          <div className="space-y-1">
            {album.album_date && <p className="text-[13px] tabular-nums text-ink/65">{formatDate(album.album_date, locale)}{album.link?.name ? <> · <bdi>{album.link.name}</bdi></> : null}</p>}
            {album.description && <p dir="auto" className="whitespace-pre-line text-[15px] text-ink/80">{album.description}</p>}
          </div>
          {album.photos.length === 0 ? <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty icon="camera" text={t('no_photos')} /></div>
            : <PhotoGrid photos={album.photos} label={t('photos')} onOpen={setViewer} />}
          {viewer !== null && <Lightbox album={album} photos={album.photos} index={viewer} onIndex={setViewer} onClose={() => setViewer(null)} />}
        </div>
      )}
    </PortalLayout>
  )
}
