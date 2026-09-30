import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { galleryApi, type AlbumLinkType } from '../../api/gallery'
import { useAuth } from '../../app/AuthContext'
import { Card, CardTitle } from '../../components/ui'
import { AlbumCard } from './shared'

/**
 * The albums linked to a class, level or competition, on that record's own page. Read from the gallery by the link
 * (nothing is copied). Hidden when the user cannot view the gallery or when there are no albums.
 */
export default function LinkedAlbums({ type, id, className }: { type: AlbumLinkType; id: number; className?: string }) {
  const { t } = useTranslation('gallery')
  const { can } = useAuth()
  const navigate = useNavigate()
  const allowed = can('gallery.view')
  const q = useQuery({
    queryKey: ['gallery-albums', { link_type: type, link_id: id, term_id: 'all', per_page: 6 }],
    queryFn: () => galleryApi.albums({ link_type: type, link_id: id, term_id: 'all', per_page: 6 }),
    enabled: allowed,
  })
  if (!allowed || !q.data || q.data.data.length === 0) return null
  return (
    <Card className={className}>
      <CardTitle actions={q.data.meta.total > q.data.data.length && <Link to="/gallery" className="text-sm font-medium text-brand-700 hover:underline">{t('all_albums')}</Link>}>
        {t('linked_albums')}
      </CardTitle>
      <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {q.data.data.map((a) => <li key={a.id}><AlbumCard album={a} href={`/gallery/${a.id}`} onOpen={() => navigate(`/gallery/${a.id}`)} /></li>)}
      </ul>
    </Card>
  )
}
