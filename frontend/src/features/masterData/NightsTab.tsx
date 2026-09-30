import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { nightsApi, type Night } from '../../api/masterData'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { Badge, buttonClass, EmptyCard, ErrorState, LoadingState, Notice, SecondaryButton, SURFACE, TABLE_HEAD, TableWrap, TextInput } from '../../components/ui'
import type { CrudNotice } from '../common/crud'

/** الليالي: the weekdays the centre runs, switched on or off, with default times. */
export function NightsTab() {
  const { t } = useTranslation('masterData')
  const q = useQuery({ queryKey: ['nights'], queryFn: nightsApi.list })
  const [notice, setNotice] = useState<CrudNotice>(null)

  return (
    <div className="space-y-4">
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <p className="text-sm text-ink/60">{t('nights.hint')}</p>
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : (
        <ul className="grid gap-3 *:min-w-0 sm:grid-cols-2 xl:grid-cols-4">
          {q.data.map((n) => <NightCard key={n.id} night={n} onNotice={setNotice} />)}
        </ul>
      )}
    </div>
  )
}

function NightCard({ night, onNotice }: { night: Night; onNotice: (n: CrudNotice) => void }) {
  const { t } = useTranslation('masterData')
  const qc = useQueryClient()
  const [form, setForm] = useState({ start_time: night.start_time ?? '', end_time: night.end_time ?? '' })
  const dirty = form.start_time !== (night.start_time ?? '') || form.end_time !== (night.end_time ?? '')
  const save = useMutation({
    mutationFn: (d: Partial<Night>) => nightsApi.update(night.id, d),
    onSuccess: (r) => { onNotice({ tone: 'success', text: r.message }); void qc.invalidateQueries({ queryKey: ['nights'] }) },
    onError: (e) => { const p = parseApiError(e); onNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })

  return (
    <li className={`${SURFACE} p-4 ${night.is_active ? '' : 'border-dashed opacity-75'}`}>
      <div className="flex items-center justify-between gap-2">
        <p className="font-semibold text-ink">{t(`lessons:days.${night.weekday}`)}</p>
        <Badge tone={night.is_active ? 'brand' : 'muted'}>{night.is_active ? t('nights.active') : t('nights.off')}</Badge>
      </div>
      <div className="mt-3 grid grid-cols-2 gap-2">
        <TextInput label={t('nights.from')} type="time" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} />
        <TextInput label={t('nights.to')} type="time" value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} />
      </div>
      <div className="mt-3 flex flex-wrap gap-2">
        {dirty && <SecondaryButton disabled={save.isPending} onClick={() => save.mutate({ start_time: form.start_time || null, end_time: form.end_time || null })}>{t('nights.save_times')}</SecondaryButton>}
        <SecondaryButton disabled={save.isPending} onClick={() => save.mutate({ is_active: !night.is_active })}>{night.is_active ? t('nights.turn_off') : t('nights.turn_on')}</SecondaryButton>
      </div>
    </li>
  )
}

/** المشرفين: supervisor accounts and the nights each covers in the selected term. */
export function SupervisorsTab() {
  const { t, i18n } = useTranslation('masterData')
  const { can } = useAuth()
  const q = useQuery({ queryKey: ['master-supervisors'], queryFn: nightsApi.supervisors })
  const days = (list: string[]) => list.map((d) => t(`lessons:days.${d}`)).join(i18n.language === 'ar' ? '، ' : ', ')

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <p className="min-w-0 flex-1 text-sm text-ink/60">{t('supervisors.hint')}</p>
        {can('users.manage') && <Link to="/users?tab=users" className={buttonClass('secondary')}><Icon name="users" className="size-4" />{t('supervisors.manage_accounts')}</Link>}
        {can('term_setup.manage') && <Link to="/term-setup?tab=supervisors" className={buttonClass('secondary')}><Icon name="clock" className="size-4" />{t('supervisors.assign_nights')}</Link>}
      </div>
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.length === 0 ? (
        <EmptyCard icon="users" title={t('supervisors.empty')} />
      ) : (
        <TableWrap surface>
          <table className="w-full min-w-[32rem] text-sm">
            <thead className={TABLE_HEAD}>
              <tr>
                <th scope="col" className="px-4 py-3 text-start font-medium">{t('supervisors.name')}</th>
                <th scope="col" className="px-4 py-3 text-start font-medium">{t('supervisors.phone')}</th>
                <th scope="col" className="px-4 py-3 text-start font-medium">{t('supervisors.nights')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink/6">
              {q.data.map((s) => (
                <tr key={s.id} className={s.is_active ? '' : 'opacity-60'}>
                  <td className="px-4 py-3"><bdi className="font-medium text-ink">{s.name}</bdi>{!s.is_active && <Badge tone="muted" className="ms-2">{t('inactive')}</Badge>}</td>
                  <td className="px-4 py-3 tabular-nums text-ink/70" dir="ltr">{s.phone}</td>
                  <td className="px-4 py-3 text-ink/70">{s.nights.length ? days(s.nights) : <span className="text-ink/40">{t('supervisors.no_nights')}</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableWrap>
      )}
    </div>
  )
}

