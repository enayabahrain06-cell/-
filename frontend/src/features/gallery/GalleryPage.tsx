import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { galleryApi, type AlbumLinkType, type AlbumVisibility } from '../../api/gallery'
import Icon from '../../components/Icon'
import { PageBand } from '../../components/ornaments'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { buttonClass, EmptyCard, ErrorState, FilterBar, LoadingState, SearchInput } from '../../components/ui'
import AlbumFormModal from './AlbumFormModal'
import { AlbumCard } from './shared'
import { linkOptions } from './utils'
import { useTermScope } from '../../app/term'

/** معرض الصور: the term's albums (the shell's term selector), filtered by link and visibility, newest first. */
export default function GalleryPage() {
  const { t } = useTranslation('gallery')
  const ts = useTermScope('all')
  const navigate = useNavigate()
  const opts = useQuery({ queryKey: ['gallery-options'], queryFn: galleryApi.options })
  const [linkType, setLinkType] = useState<AlbumLinkType | ''>('')
  const [linkId, setLinkId] = useState('')
  const [visibility, setVisibility] = useState<AlbumVisibility | ''>('')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)

  const filters = { link_type: linkType || undefined, link_id: linkType && linkId ? Number(linkId) : undefined, visibility: visibility || undefined, q: q.trim() || undefined, page }
  const albums = useQuery({ queryKey: ['gallery-albums', filters], queryFn: () => galleryApi.albums(filters), placeholderData: keepPreviousData })
  const o = opts.data
  const reset = () => setPage(1)

  const newButton = o?.can_create && (
    <button type="button" onClick={() => setCreating(true)} className={buttonClass('onDeep')}><Icon name="plus" className="size-4" />{t('new_album')}</button>
  )

  return (
    <div className="space-y-5">
      <div className="hidden lg:block"><PageBand title={t('nav:menu.gallery')} subtitle={t('subtitle')} actions={newButton} /></div>
      {o?.can_create && (
        <button type="button" onClick={() => setCreating(true)} className={buttonClass('primary', 'w-full lg:hidden')}><Icon name="plus" className="size-4" />{t('new_album')}</button>
      )}

      <FilterBar label={t('filters')} layout="grid" className="sm:grid-cols-2 xl:grid-cols-4">
        <SearchInput label={t('search')} placeholder={t('search')} value={q} onChange={(e) => { setQ(e.target.value); reset() }} />
        <SelectField label={t('link_type')} hideLabel value={linkType} onChange={(e) => { setLinkType(e.target.value as AlbumLinkType | ''); setLinkId(''); reset() }}
          options={[{ value: '', label: t('all_links') }, ...(o?.link_types ?? []).map((k) => ({ value: k, label: t(`link.${k}`) }))]} />
        {linkType && o && (
          <SelectField label={t(`link.${linkType}`)} hideLabel value={linkId} onChange={(e) => { setLinkId(e.target.value); reset() }}
            options={[{ value: '', label: t('all_of', { what: t(`link.${linkType}`) }) }, ...linkOptions(o, linkType)]} />
        )}
        <SelectField label={t('visibility_label')} hideLabel value={visibility} onChange={(e) => { setVisibility(e.target.value as AlbumVisibility | ''); reset() }}
          options={[{ value: '', label: t('all_visibility') }, ...(['staff', 'linked', 'all_guardians'] as const).map((v) => ({ value: v, label: t(`visibility.${v}`) }))]} />
      </FilterBar>

      {albums.isLoading || opts.isLoading ? <LoadingState /> : albums.isError ? <ErrorState onRetry={() => void albums.refetch()} /> : albums.data && albums.data.data.length === 0 ? (
        <EmptyCard icon="camera" title={t('empty', { scope: ts.scope() })} body={o?.can_create ? t('empty_create') : undefined} />
      ) : albums.data && (
        <>
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4" aria-label={t('albums')}>
            {albums.data.data.map((a) => (
              <li key={a.id}><AlbumCard album={a} href={`/gallery/${a.id}`} onOpen={() => navigate(`/gallery/${a.id}`)} /></li>
            ))}
          </ul>
          <Pagination page={albums.data.meta.current_page} lastPage={albums.data.meta.last_page} total={albums.data.meta.total} onPage={setPage} />
        </>
      )}

      {creating && o && <AlbumFormModal options={o} onClose={() => setCreating(false)} onSaved={(a) => navigate(`/gallery/${a.id}`)} />}
    </div>
  )
}
