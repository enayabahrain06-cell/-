import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { termSetupApi } from '../../api/termSetup'
import SelectField from '../../components/SelectField'
import { ErrorState, IconButton, LoadingState, Notice, SecondaryButton, SURFACE } from '../../components/ui'
import { useRemove } from '../common/crud'
import { WEEKDAYS, useCanManage, useSetupOptions } from './shared'

/** مشرفو الليالي: who is on duty each weekday of the term. */
export default function SupervisorsTab() {
  const { t } = useTranslation('termSetup')
  const canManage = useCanManage()
  const options = useSetupOptions()
  const q = useQuery({ queryKey: ['term-setup-supervisors'], queryFn: termSetupApi.supervisors })
  const { notice, setNotice, remove } = useRemove(termSetupApi.removeSupervisor, [['term-setup-supervisors']], t('supervisors.delete_confirm'))

  if (q.isLoading) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />

  return (
    <div className="space-y-4">
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <p className="text-sm text-ink/60">{t('supervisors.hint')}</p>
      <ul className="grid gap-3 *:min-w-0 sm:grid-cols-2 xl:grid-cols-4">
        {WEEKDAYS.map((d) => {
          const rows = (q.data?.data ?? []).filter((r) => r.weekday === d)
          return (
            <li key={d} className={`${SURFACE} p-4`}>
              <p className="font-semibold text-ink">{t(`lessons:days.${d}`)}</p>
              {rows.length === 0 ? <p className="mt-2 text-sm text-ink/50">{t('supervisors.none')}</p> : (
                <ul className="mt-2 space-y-1">
                  {rows.map((r) => (
                    <li key={r.id} className="flex items-center gap-2 text-sm">
                      <bdi className="min-w-0 flex-1 truncate text-ink">{r.user?.name}</bdi>
                      {canManage && <IconButton icon="trash" tone="danger" label={t('delete')} onClick={() => remove(r.id)} />}
                    </li>
                  ))}
                </ul>
              )}
              {canManage && options.data && (
                <AddSupervisor weekday={d} termId={options.data.term.id} choices={options.data.supervisors.filter((s) => !rows.some((r) => r.user?.id === s.id))}
                  onError={(m) => setNotice({ tone: 'error', text: m })} />
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

function AddSupervisor({ weekday, termId, choices, onError }: { weekday: string; termId: number; choices: { id: number; name: string }[]; onError: (m: string) => void }) {
  const { t } = useTranslation('termSetup')
  const qc = useQueryClient()
  const [userId, setUserId] = useState('')
  const add = useMutation({
    mutationFn: () => termSetupApi.addSupervisor({ academic_term_id: termId, weekday, user_id: Number(userId) }),
    onSuccess: () => { setUserId(''); void qc.invalidateQueries({ queryKey: ['term-setup-supervisors'] }) },
    onError: (e) => { const p = parseApiError(e); onError(Object.values(p.fields)[0]?.[0] ?? p.message) },
  })
  if (choices.length === 0) return null

  return (
    <div className="mt-3 flex gap-2">
      <SelectField label={t('supervisors.add')} hideLabel className="min-w-0 flex-1" value={userId} onChange={(e) => setUserId(e.target.value)}
        options={[{ value: '', label: t('supervisors.choose') }, ...choices.map((s) => ({ value: String(s.id), label: s.name }))]} />
      <SecondaryButton disabled={!userId} onClick={() => add.mutate()}>{t('supervisors.add')}</SecondaryButton>
    </div>
  )
}
