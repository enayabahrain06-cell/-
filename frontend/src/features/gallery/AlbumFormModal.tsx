import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { galleryApi, type Album, type AlbumGender, type AlbumInput, type AlbumLinkType, type GalleryOptions } from '../../api/gallery'
import SelectField from '../../components/SelectField'
import { Modal, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput } from '../../components/ui'
import { linkOptions } from './utils'
import { useTermScope } from '../../app/term'

/**
 * New album or album details. Teachers link to one of their own classes (their albums stay staff-only); managers
 * may also link a level or competition, or nothing. The track follows a linked class or competition; otherwise it
 * is chosen here (single-track staff get their own).
 */
export default function AlbumFormModal({ options: o, album, onClose, onSaved }: { options: GalleryOptions; album?: Album; onClose: () => void; onSaved?: (a: Album) => void }) {
  const { t } = useTranslation('gallery')
  const ts = useTermScope()
  const qc = useQueryClient()
  const types: AlbumLinkType[] = o.can_manage ? o.link_types : ['lesson']
  const [title, setTitle] = useState(album?.title ?? '')
  const [description, setDescription] = useState(album?.description ?? '')
  const [date, setDate] = useState(album?.album_date ?? new Date().toISOString().slice(0, 10))
  const [linkType, setLinkType] = useState<AlbumLinkType | ''>(album?.link?.type ?? (o.can_manage ? '' : 'lesson'))
  const [linkId, setLinkId] = useState(album?.link ? String(album.link.id) : o.can_manage ? '' : String(o.classes[0]?.id ?? ''))
  const [gender, setGender] = useState<AlbumGender | ''>(album?.gender ?? o.track ?? '')
  const needsGender = (linkType === '' || linkType === 'level') && !o.track

  const save = useMutation({
    mutationFn: () => {
      const data: AlbumInput = {
        title: title.trim(), description: description.trim() || null, album_date: date,
        link_type: linkType || null, link_id: linkType ? Number(linkId) : null,
        gender: linkType === 'lesson' || linkType === 'competition' || linkType === 'activity' ? undefined : (gender || null),
      }
      return album ? galleryApi.update(album.id, data) : galleryApi.create(data)
    },
    onSuccess: (a) => {
      void qc.invalidateQueries({ queryKey: ['gallery-albums'] })
      void qc.invalidateQueries({ queryKey: ['gallery-album', a.id] })
      onSaved?.(a)
      onClose()
    },
  })
  const err = save.error ? parseApiError(save.error) : null
  const submit = (e: FormEvent) => { e.preventDefault(); save.mutate() }

  if (!o.can_manage && o.classes.length === 0) {
    return <Modal title={t('new_album')} onClose={onClose}><Notice tone="info">{t('no_classes', { scope: ts.scope() })}</Notice></Modal>
  }

  return (
    <Modal title={album ? t('edit_album') : t('new_album')} onClose={onClose}
      footer={<>
        <SecondaryButton type="button" onClick={onClose}>{t('cancel')}</SecondaryButton>
        <PrimaryButton type="submit" form="album-form" loading={save.isPending}>{t('save')}</PrimaryButton>
      </>}>
      <form id="album-form" onSubmit={submit} className="space-y-4">
        {err && <Notice tone="error">{err.message}</Notice>}
        <TextInput label={t('title')} value={title} onChange={(e) => setTitle(e.target.value)} required maxLength={200} dir="auto" />
        <TextArea label={t('description')} value={description} onChange={(e) => setDescription(e.target.value)} rows={3} maxLength={2000} dir="auto" />
        <TextInput label={t('date')} type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
        <div className="grid gap-3 sm:grid-cols-2">
          <SelectField label={t('link_type')} value={linkType} onChange={(e) => { setLinkType(e.target.value as AlbumLinkType | ''); setLinkId('') }}
            options={[...(o.can_manage ? [{ value: '', label: t('no_link') }] : []), ...types.map((k) => ({ value: k, label: t(`link.${k}`) }))]} />
          {linkType && (
            <SelectField label={t(`link.${linkType}`)} value={linkId} onChange={(e) => setLinkId(e.target.value)} required
              options={[{ value: '', label: t('choose') }, ...linkOptions(o, linkType)]} />
          )}
        </div>
        {needsGender && (
          <SelectField label={t('track')} value={gender} onChange={(e) => setGender(e.target.value as AlbumGender | '')} required
            options={[{ value: '', label: t('choose') }, ...(['male', 'female', 'mixed'] as const).map((g) => ({ value: g, label: t(`gender.${g}`) }))]} />
        )}
        {!o.can_manage && <p className="text-xs text-ink/55">{t('teacher_staff_only')}</p>}
      </form>
    </Modal>
  )
}
