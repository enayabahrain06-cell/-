import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api, parseApiError } from '../../api/client'
import { challengesApi, competitionsApi, honorApi } from '../../api/engagement'
import type { StudentSummary } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { OrnamentDivider, PageBand } from '../../components/ornaments'
import { Badge, Card, CardTitle, LoadingState, Notice, PrimaryButton, SURFACE } from '../../components/ui'
import FamilyLayout from '../../layouts/FamilyLayout'
import { formatDate, formatNumber } from '../../lib/format'
import { goalText, ProgressBar } from '../competitions/ChallengePages'
import { BREAKDOWN_COLORS, MEDAL, monthLabel } from './HonorBoardPage'

/** Student / guardian: own place on the honor board, badges, competitions and challenges with join buttons. */
export default function MyEngagementPage() {
  const { t, i18n } = useTranslation('engagement')
  const locale = i18n.language
  const { hasRole } = useAuth()
  const qc = useQueryClient()
  const guardian = hasRole('guardian') && !hasRole('student')
  const children = useQuery({ queryKey: ['me-students'], queryFn: () => api.get<{ data: StudentSummary[] }>('/me/students').then((r) => r.data.data), enabled: guardian })
  const [childId, setChildId] = useState<number | undefined>(undefined)
  const studentId = guardian ? childId ?? children.data?.[0]?.id : undefined
  const ready = !guardian || !!studentId
  const honor = useQuery({ queryKey: ['my-honor', studentId, locale], queryFn: () => honorApi.mine(studentId), enabled: ready })
  const comps = useQuery({ queryKey: ['my-competitions', studentId, locale], queryFn: () => competitionsApi.mine(studentId), enabled: ready })
  const chals = useQuery({ queryKey: ['my-challenges', studentId, locale], queryFn: () => challengesApi.mine(studentId), enabled: ready })
  const [error, setError] = useState<string | null>(null)
  const onErr = (e: unknown) => setError(parseApiError(e).message)
  const register = useMutation({ mutationFn: (id: number) => competitionsApi.registerSelf(id, studentId), onSuccess: () => void qc.invalidateQueries({ queryKey: ['my-competitions'] }), onError: onErr })
  const join = useMutation({ mutationFn: (id: number) => challengesApi.join(id, studentId), onSuccess: () => void qc.invalidateQueries({ queryKey: ['my-challenges'] }), onError: onErr })
  const n = (v: number, d = 0) => formatNumber(v, locale, { maximumFractionDigits: d })
  const d = (v: string) => formatDate(v, locale, { day: 'numeric', month: 'short' })
  const h = honor.data

  return (
    <FamilyLayout>
      <div className="space-y-5">
        <PageBand title={t('my.title')} subtitle={h ? monthLabel(h.period, locale) : undefined} />
        {guardian && (children.data?.length ?? 0) > 1 && (
          <SelectField className="w-64" label={t('my.child')} value={studentId ?? ''} onChange={(e) => setChildId(Number(e.target.value))}
            options={(children.data ?? []).map((c) => ({ value: String(c.id), label: c.full_name }))} />
        )}
        {error && <Notice tone="error">{error}</Notice>}

        {honor.isLoading ? <LoadingState /> : h && (
          <div className="grid gap-4 md:grid-cols-2">
            <Card>
              <CardTitle>{t('my.my_rank')}</CardTitle>
              {h.mine ? (
                <div className="flex items-center gap-4">
                  <span className="grid size-16 shrink-0 place-items-center rounded-full bg-gold-500/15"><Icon name="medal" className={`size-8 ${h.mine.rank_in_track && h.mine.rank_in_track <= 3 ? MEDAL[h.mine.rank_in_track - 1] : 'text-brand-600'}`} /></span>
                  <div className="min-w-0 flex-1 space-y-1">
                    <p className="text-2xl font-semibold tabular-nums text-ink">{t('my.rank_of', { n: n(h.mine.rank_in_track ?? 0) })}</p>
                    <p className="text-sm text-ink/60">{t('honor.points_n', { n: n(h.mine.points, 1) })}</p>
                    <ul className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-ink/60">
                      {(['attendance', 'evaluation', 'memorization', 'bonus'] as const).map((k) => (
                        <li key={k} className="inline-flex items-center gap-1"><span className="size-2 rounded-sm" style={{ background: BREAKDOWN_COLORS[k] }} aria-hidden />{t(`honor.${k}`)} {n(h.mine!.breakdown[k], 1)}</li>
                      ))}
                    </ul>
                  </div>
                </div>
              ) : <p className="text-sm text-ink/55">{t('my.not_published')}</p>}
            </Card>
            <Card>
              <CardTitle>{t('my.badges')}</CardTitle>
              {h.badges.length === 0 ? <p className="text-sm text-ink/55">{t('my.no_badges')}</p> : (
                <ul className="flex flex-wrap gap-2">
                  {h.badges.map((b) => <li key={b.id}><Badge tone="gold"><Icon name="medal" className="size-3.5" /> {b.badge}</Badge></li>)}
                </ul>
              )}
            </Card>
            {h.board && h.board.rows.length > 0 && (
              <Card className="md:col-span-2">
                <CardTitle>{t('my.board')}</CardTitle>
                <ol className="grid gap-1.5 sm:grid-cols-2">
                  {h.board.rows.map((r) => (
                    <li key={r.student?.id} className={`flex items-center gap-2 rounded-xl px-3 py-1.5 text-sm ${r.student?.id === h.student.id ? 'bg-gold-500/15 font-semibold' : 'bg-page/60'}`}>
                      <span className="w-6 tabular-nums text-ink/60">{n(r.rank ?? 0)}</span>
                      <span dir="auto" className="flex-1 truncate">{r.student?.full_name}</span>
                      <span className="tabular-nums text-brand-700">{n(r.points, 1)}</span>
                    </li>
                  ))}
                </ol>
              </Card>
            )}
          </div>
        )}

        <OrnamentDivider align="center" className="text-gold-500" />

        <section aria-labelledby="my-comp" className="space-y-3">
          <h2 id="my-comp" className="text-lg font-semibold text-ink">{t('my.competitions')}</h2>
          {comps.isLoading ? <LoadingState /> : comps.data && (
            <div className="grid gap-3 md:grid-cols-2">
              {comps.data.open.map((c) => (
                <div key={`o${c.id}`} className={`${SURFACE} p-4`}>
                  <div className="flex items-start justify-between gap-2"><p dir="auto" className="font-semibold text-ink">{c.name}</p><Badge tone="brand">{t('competitions.status.open')}</Badge></div>
                  <p className="mt-1 text-sm text-ink/60">{t(`competitions.type.${c.type}`)} · {t('competitions.registration', { from: d(c.registration_opens_at), to: d(c.registration_closes_at) })}</p>
                  <PrimaryButton className="mt-3" loading={register.isPending && register.variables === c.id} onClick={() => register.mutate(c.id)}>{t('my.register')}</PrimaryButton>
                </div>
              ))}
              {comps.data.mine.map((c) => (
                <div key={`m${c.id}`} className={`${SURFACE} p-4`}>
                  <div className="flex items-start justify-between gap-2"><p dir="auto" className="font-semibold text-ink">{c.name}</p><Badge tone={c.published ? 'gold' : 'muted'}>{t(`competitions.status.${c.status}`)}</Badge></div>
                  <ul className="mt-2 space-y-1 text-sm text-ink/65">
                    {c.rounds.map((r) => <li key={r.id} className="flex items-center gap-2"><Icon name="clock" className="size-4 text-ink/40" /><span dir="auto">{r.name}</span><span className="ms-auto">{d(r.round_date)}{r.start_time && ` · ${r.start_time}`}</span></li>)}
                  </ul>
                  <p className="mt-2 text-sm">{c.published && c.final_rank ? <Badge tone="gold"><Icon name="medal" className="size-3.5" /> {t('my.result', { n: n(c.final_rank) })}</Badge> : <span className="text-ink/50">{t('my.result_pending')}</span>}</p>
                </div>
              ))}
              {comps.data.open.length + comps.data.mine.length === 0 && <p className="text-sm text-ink/55">{t('my.nothing_open')}</p>}
            </div>
          )}
        </section>

        <section aria-labelledby="my-chal" className="space-y-3">
          <h2 id="my-chal" className="text-lg font-semibold text-ink">{t('my.challenges')}</h2>
          {chals.isLoading ? <LoadingState /> : chals.data && (
            <div className="grid gap-3 md:grid-cols-2">
              {chals.data.mine.map((c) => (
                <div key={`m${c.id}`} className={`${SURFACE} space-y-2 p-4`}>
                  <div className="flex items-start justify-between gap-2"><p dir="auto" className="font-semibold text-ink">{c.name}</p><Badge tone={c.participant_status === 'completed' ? 'gold' : c.participant_status === 'failed' ? 'muted' : 'brand'}>{t(`challenges.p_status.${c.participant_status}`)}</Badge></div>
                  <p dir="auto" className="text-sm text-ink/65">{goalText(c, t, locale)}</p>
                  <ProgressBar value={c.progress_pct} label={`${c.name}: ${c.progress_pct}%`} tone={c.participant_status === 'completed' ? 'gold' : 'brand'} />
                  <p className="flex justify-between text-xs text-ink/55"><span className="tabular-nums">{n(c.progress_value)} / {n(c.goal_value)}</span>{c.participant_status === 'joined' && c.days_left !== null && <span>{t('challenges.days_left', { n: n(c.days_left) })}</span>}</p>
                </div>
              ))}
              {chals.data.open.map((c) => (
                <div key={`o${c.id}`} className={`${SURFACE} space-y-2 border-dashed p-4`}>
                  <p dir="auto" className="font-semibold text-ink">{c.name}</p>
                  <p dir="auto" className="text-sm text-ink/65">{goalText(c, t, locale)}</p>
                  <p className="text-xs text-ink/55">{t('challenges.dates', { from: d(c.starts_at), to: d(c.ends_at) })} · {t('challenges.reward', { points: n(c.reward_points) })}</p>
                  <PrimaryButton loading={join.isPending && join.variables === c.id} onClick={() => join.mutate(c.id)}>{t('my.join')}</PrimaryButton>
                </div>
              ))}
              {chals.data.open.length + chals.data.mine.length === 0 && <p className="text-sm text-ink/55">{t('my.nothing_open')}</p>}
            </div>
          )}
        </section>
      </div>
    </FamilyLayout>
  )
}
