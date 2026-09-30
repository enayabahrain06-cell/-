import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { termSetupApi, type LevelRoomRow } from '../../api/termSetup'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, IconButton, LoadingState, Notice, SecondaryButton, SURFACE, type Tone } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { useRemove, type CrudNotice } from '../common/crud'
import { useCanManage, useSetupOptions } from './shared'

const SOURCE_TONE: Record<string, Tone> = { assigned: 'brand', circle: 'info', timetable: 'muted' }

/** غرف المستويات: each level's rooms this term — assigned here, or already used by its circles and timetable. */
export default function LevelRoomsTab() {
  const { t, i18n } = useTranslation('termSetup')
  const canManage = useCanManage()
  const options = useSetupOptions()
  const q = useQuery({ queryKey: ['term-setup-level-rooms'], queryFn: termSetupApi.levelRooms })
  const { notice, setNotice, remove } = useRemove(termSetupApi.removeLevelRoom, [['term-setup-level-rooms'], ['term-setup-options']], t('level_rooms.delete_confirm'))

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState onRetry={() => void q.refetch()} />
  if (q.data.data.length === 0) return <EmptyCard icon="lessons" title={t('no_levels')} />

  return (
    <div className="space-y-4">
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <p className="text-sm text-ink/60">{t('level_rooms.hint')}</p>
      <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
        {q.data.data.map((row) => (
          <li key={row.level.id} className={`${SURFACE} p-4`}>
            <div className="flex items-center justify-between gap-2">
              <p dir="auto" className="font-semibold text-ink">{row.level.name}</p>
              <Badge tone="muted">{t('level_rooms.count', { n: formatNumber(row.rooms.length, i18n.language) })}</Badge>
            </div>
            {row.rooms.length === 0 ? <p className="mt-2 text-sm text-ink/50">{t('level_rooms.none')}</p> : (
              <ul className="mt-2 divide-y divide-ink/6">
                {row.rooms.map((r) => (
                  <li key={r.location.id} className="flex items-start gap-2 py-2 text-sm">
                    <div className="min-w-0 flex-1">
                      <p dir="auto" className="font-medium text-ink">{r.location.name}</p>
                      <div className="mt-1 flex flex-wrap gap-1.5">
                        {r.sources.map((s) => <Badge key={s} tone={SOURCE_TONE[s]}>{t(`level_rooms.source.${s}`)}</Badge>)}
                      </div>
                      {r.circles.length > 0 && <p className="mt-1 text-xs text-ink/55"><bdi>{r.circles.join(t('list_sep'))}</bdi></p>}
                    </div>
                    {canManage && r.level_room_id && <IconButton icon="trash" tone="danger" label={t('delete')} onClick={() => remove(r.level_room_id!)} />}
                  </li>
                ))}
              </ul>
            )}
            {canManage && options.data && (
              <AddRoom row={row} termId={options.data.term.id} halls={options.data.halls} onNotice={setNotice} />
            )}
          </li>
        ))}
      </ul>
    </div>
  )
}

function AddRoom({ row, termId, halls, onNotice }: { row: LevelRoomRow; termId: number; halls: { id: number; name: string }[]; onNotice: (n: CrudNotice) => void }) {
  const { t } = useTranslation('termSetup')
  const qc = useQueryClient()
  const [locationId, setLocationId] = useState('')
  const taken = row.rooms.filter((r) => r.level_room_id).map((r) => r.location.id)
  const choices = halls.filter((h) => !taken.includes(h.id))
  const add = useMutation({
    mutationFn: () => termSetupApi.addLevelRoom({ academic_term_id: termId, level_id: row.level.id, location_id: Number(locationId) }),
    onSuccess: (r) => {
      setLocationId('')
      onNotice({ tone: 'success', text: r.message })
      void qc.invalidateQueries({ queryKey: ['term-setup-level-rooms'] })
      void qc.invalidateQueries({ queryKey: ['term-setup-options'] })
    },
    onError: (e) => { const p = parseApiError(e); onNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })
  if (choices.length === 0) return null

  return (
    <div className="mt-3 flex gap-2">
      <SelectField label={t('level_rooms.add')} hideLabel className="min-w-0 flex-1" value={locationId} onChange={(e) => setLocationId(e.target.value)}
        options={[{ value: '', label: t('level_rooms.choose') }, ...choices.map((h) => ({ value: String(h.id), label: h.name }))]} />
      <SecondaryButton disabled={!locationId || add.isPending} onClick={() => add.mutate()}>{t('level_rooms.add')}</SecondaryButton>
    </div>
  )
}
