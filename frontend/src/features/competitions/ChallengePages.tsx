import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { challengesApi, honorApi, type ChallengeDetail, type ChallengeInput, type ChallengeRow, type GoalType } from '../../api/engagement'
import { quranApi } from '../../api/attendance'
import { lessonsApi, optionsApi } from '../../api/lessons'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import StudentPicker from '../../components/StudentPicker'
import { EmptyState } from '../../components/ornaments'
import { Badge, Card, CardTitle, ErrorState, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput, type Tone, EmptyCard, SURFACE } from '../../components/ui'
import type { StudentSummary } from '../../api/students'
import { formatDate, formatNumber } from '../../lib/format'

export const CH_TONE: Record<ChallengeRow['status'], Tone> = { draft: 'muted', active: 'brand', finished: 'gold', cancelled: 'muted' }
const GOALS: GoalType[] = ['memorize_range', 'attendance_days', 'revision_range', 'score_streak', 'points']

export function goalText(c: ChallengeRow, t: TFunction<'engagement'>, locale: string) {
  const n = (v: number | null) => formatNumber(v ?? 0, locale)
  switch (c.goal_type) {
    case 'memorize_range':
    case 'revision_range':
      return t(`challenges.goal_text.${c.goal_type}`, { surah: c.surah_name ?? '', from: n(c.from_ayah), to: n(c.to_ayah) })
    case 'score_streak':
      return t('challenges.goal_text.score_streak', { n: n(c.goal_value), criterion: t(`challenges.criterion.${c.score_criterion ?? 'tajweed'}`), min: n(c.min_score ?? 8) })
    default:
      return t(`challenges.goal_text.${c.goal_type}`, { n: n(c.goal_value) })
  }
}

/** Accessible progress bar (value is a percentage). */
export function ProgressBar({ value, label, tone = 'brand' }: { value: number; label: string; tone?: 'brand' | 'gold' }) {
  return (
    <div className="h-2.5 w-full overflow-hidden rounded-full bg-ink/8" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={value} aria-label={label}>
      <div className={`h-full rounded-full ${tone === 'gold' ? 'bg-gold-500' : 'bg-brand-600'}`} style={{ width: `${Math.max(2, Math.min(100, value))}%` }} />
    </div>
  )
}

export function ChallengesPanel({ creating, onCloseCreate }: { creating: boolean; onCloseCreate: () => void }) {
  const { t, i18n } = useTranslation('engagement')
  const locale = i18n.language
  const navigate = useNavigate()
  const q = useQuery({ queryKey: ['challenges', locale], queryFn: () => challengesApi.list({ per_page: 50 }) })
  const d = (v: string) => formatDate(v, locale, { day: 'numeric', month: 'short' })
  return (
    <>
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <EmptyCard icon="flag" title={t('challenges.empty')} body={t('challenges.empty_body')} />
      ) : (
        <ul className="grid gap-3 *:min-w-0 md:grid-cols-2">
          {q.data.data.map((c) => {
            const pct = c.participants_count ? Math.round(((c.completed_count ?? 0) * 100) / c.participants_count) : 0
            return (
              <li key={c.id}>
                <Link to={`/competitions/challenges/${c.id}`} className={`${SURFACE} block h-full p-4 hover:border-brand-500/40`}>
                  <div className="flex items-start justify-between gap-2">
                    <p dir="auto" className="font-semibold text-ink">{c.name}</p>
                    <span className="flex shrink-0 gap-1"><Badge tone={CH_TONE[c.status]}>{t(`challenges.status.${c.status}`)}</Badge><Badge tone={c.gender === 'female' ? 'gold' : 'brand'}>{c.gender === 'female' ? t('display.girls') : t('display.boys')}</Badge></span>
                  </div>
                  <p dir="auto" className="mt-1 text-sm text-ink/70">{goalText(c, t, locale)}</p>
                  <p className="mt-1 text-xs text-ink/50">{t('challenges.dates', { from: d(c.starts_at), to: d(c.ends_at) })} · {t('challenges.counts', { p: formatNumber(c.participants_count ?? 0, locale), c: formatNumber(c.completed_count ?? 0, locale) })}</p>
                  <div className="mt-2"><ProgressBar value={pct} label={t('challenges.counts', { p: c.participants_count ?? 0, c: c.completed_count ?? 0 })} tone="gold" /></div>
                </Link>
              </li>
            )
          })}
        </ul>
      )}
      {creating && <ChallengeForm onClose={onCloseCreate} onSaved={(c) => { onCloseCreate(); navigate(`/competitions/challenges/${c.id}`) }} />}
    </>
  )
}

function ChallengeForm({ challenge, onClose, onSaved }: { challenge?: ChallengeDetail; onClose: () => void; onSaved: (c: ChallengeDetail) => void }) {
  const { t, i18n } = useTranslation('engagement')
  const locale = i18n.language
  const { user } = useAuth()
  const both = !user?.track || user.track === 'both'
  const surahs = useQuery({ queryKey: ['surahs', locale], queryFn: quranApi.surahs, staleTime: Infinity })
  const packages = useQuery({ queryKey: ['package-options'], queryFn: optionsApi.packages, staleTime: 300_000 })
  const lessons = useQuery({ queryKey: ['lessons', 'all-options'], queryFn: () => lessonsApi.list({ per_page: 200 }), staleTime: 300_000 })
  const badges = useQuery({ queryKey: ['honor-badges', locale], queryFn: honorApi.badges, staleTime: 300_000 })
  const [f, setF] = useState<ChallengeInput>(() => ({
    name_ar: challenge?.name_ar ?? '', name_en: challenge?.name_en ?? '', description: challenge?.description ?? '',
    gender: challenge?.gender ?? (user?.track === 'female' ? 'female' : 'male'), scope: challenge?.scope ?? 'authority',
    scope_lesson_id: challenge?.scope_lesson_id ?? null, scope_package_id: challenge?.scope_package_id ?? null,
    min_age: challenge?.min_age ?? null, max_age: challenge?.max_age ?? null,
    goal_type: challenge?.goal_type ?? 'attendance_days', goal_value: challenge?.goal_value ?? 8,
    surah_number: challenge?.surah_number ?? 78, from_ayah: challenge?.from_ayah ?? 1, to_ayah: challenge?.to_ayah ?? null,
    min_score: challenge?.min_score ?? 8, score_criterion: challenge?.score_criterion ?? 'tajweed',
    starts_at: challenge?.starts_at ?? new Date().toISOString().slice(0, 10), ends_at: challenge?.ends_at ?? '',
    reward_points: challenge?.reward_points ?? 10, reward_badge_id: challenge?.reward_badge_id ?? null, status: challenge?.status ?? 'active',
  }))
  const [error, setError] = useState<string | null>(null)
  const set = <K extends keyof ChallengeInput>(k: K, v: ChallengeInput[K]) => setF((x) => ({ ...x, [k]: v }))
  const num = (v: string) => (v === '' ? null : Number(v))
  const range = f.goal_type === 'memorize_range' || f.goal_type === 'revision_range'
  const surah = surahs.data?.find((s) => s.number === f.surah_number)
  const save = useMutation({
    mutationFn: () => (challenge ? challengesApi.update(challenge.id, f) : challengesApi.create(f)),
    onSuccess: onSaved,
    onError: (e) => { const p = parseApiError(e); setError(Object.values(p.fields)[0]?.[0] ?? p.message) },
  })
  return (
    <Modal wide title={challenge ? t('challenges.form.title_edit') : t('challenges.form.title_new')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton disabled={!f.name_ar || !f.ends_at} loading={save.isPending} onClick={() => save.mutate()}>{t('form.save')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="grid gap-4 sm:grid-cols-2">
        <TextInput label={t('form.name_ar')} dir="rtl" value={f.name_ar ?? ''} onChange={(e) => set('name_ar', e.target.value)} />
        <TextInput label={t('form.name_en')} dir="ltr" value={f.name_en ?? ''} onChange={(e) => set('name_en', e.target.value)} />
        <SelectField label={t('form.gender')} value={f.gender} disabled={!both || !!challenge} onChange={(e) => set('gender', e.target.value as 'male' | 'female')} options={[{ value: 'male', label: t('display.boys') }, { value: 'female', label: t('display.girls') }]} />
        <SelectField label={t('challenges.form.status')} value={f.status ?? 'active'} onChange={(e) => set('status', e.target.value as ChallengeRow['status'])} options={(['draft', 'active', 'finished', 'cancelled'] as const).map((v) => ({ value: v, label: t(`challenges.status.${v}`) }))} />
        <SelectField label={t('challenges.form.goal_type')} value={f.goal_type} onChange={(e) => set('goal_type', e.target.value as GoalType)} options={GOALS.map((g) => ({ value: g, label: t(`challenges.goal.${g}`) }))} />
        {!range && <TextInput type="number" min={1} label={t('challenges.form.goal_value')} value={f.goal_value ?? ''} onChange={(e) => set('goal_value', Number(e.target.value))} />}
        {range && (
          <>
            <SelectField label={t('challenges.form.surah')} value={f.surah_number ?? ''} onChange={(e) => { const s = Number(e.target.value); setF((x) => ({ ...x, surah_number: s, from_ayah: 1, to_ayah: surahs.data?.find((y) => y.number === s)?.ayah_count ?? null })) }}
              options={(surahs.data ?? []).map((s) => ({ value: String(s.number), label: `${formatNumber(s.number, locale)}. ${s.name}` }))} />
            <div className="grid grid-cols-2 gap-2">
              <TextInput type="number" min={1} max={surah?.ayah_count} label={t('challenges.form.from_ayah')} value={f.from_ayah ?? ''} onChange={(e) => set('from_ayah', num(e.target.value))} />
              <TextInput type="number" min={1} max={surah?.ayah_count} label={t('challenges.form.to_ayah')} value={f.to_ayah ?? surah?.ayah_count ?? ''} onChange={(e) => set('to_ayah', num(e.target.value))} />
            </div>
          </>
        )}
        {f.goal_type === 'score_streak' && (
          <>
            <SelectField label={t('challenges.form.criterion')} value={f.score_criterion ?? 'tajweed'} onChange={(e) => set('score_criterion', e.target.value)} options={(['memorization', 'tajweed', 'revision', 'behavior', 'total'] as const).map((v) => ({ value: v, label: t(`challenges.criterion.${v}`) }))} />
            <TextInput type="number" min={0} max={10} label={t('challenges.form.min_score')} value={f.min_score ?? ''} onChange={(e) => set('min_score', num(e.target.value))} />
          </>
        )}
        <SelectField label={t('form.scope')} value={f.scope} onChange={(e) => set('scope', e.target.value as ChallengeRow['scope'])} options={(['authority', 'package', 'circle'] as const).map((v) => ({ value: v, label: t(`competitions.scope.${v}`) }))} />
        {f.scope === 'circle' ? <SelectField label={t('form.lesson')} value={f.scope_lesson_id ?? ''} onChange={(e) => set('scope_lesson_id', num(e.target.value))} options={[{ value: '', label: '—' }, ...(lessons.data?.data ?? []).filter((l) => l.gender === f.gender || l.gender === 'mixed').map((l) => ({ value: String(l.id), label: l.name }))]} />
          : f.scope === 'package' ? <SelectField label={t('form.package')} value={f.scope_package_id ?? ''} onChange={(e) => set('scope_package_id', num(e.target.value))} options={[{ value: '', label: '—' }, ...(packages.data ?? []).filter((p) => p.gender === f.gender || p.gender === 'mixed').map((p) => ({ value: String(p.id), label: p.name }))]} />
            : <span className="hidden sm:block" />}
        <TextInput type="date" label={t('challenges.form.starts')} value={f.starts_at ?? ''} onChange={(e) => set('starts_at', e.target.value)} />
        <TextInput type="date" label={t('challenges.form.ends')} value={f.ends_at ?? ''} min={f.starts_at} onChange={(e) => set('ends_at', e.target.value)} />
        <TextInput type="number" min={3} max={99} label={t('form.min_age')} value={f.min_age ?? ''} onChange={(e) => set('min_age', num(e.target.value))} />
        <TextInput type="number" min={3} max={99} label={t('form.max_age')} value={f.max_age ?? ''} onChange={(e) => set('max_age', num(e.target.value))} />
        <TextInput type="number" min={0} max={1000} label={t('challenges.form.reward_points')} value={f.reward_points ?? 0} onChange={(e) => set('reward_points', Number(e.target.value))} />
        <SelectField label={t('challenges.form.reward_badge')} value={f.reward_badge_id ?? ''} onChange={(e) => set('reward_badge_id', num(e.target.value))} options={[{ value: '', label: t('form.none') }, ...(badges.data ?? []).map((b) => ({ value: String(b.id), label: b.name }))]} />
        <div className="sm:col-span-2"><TextArea label={t('form.description')} rows={2} value={f.description ?? ''} onChange={(e) => set('description', e.target.value)} dir="auto" /></div>
      </div>
    </Modal>
  )
}

export function ChallengeDetailPage() {
  const { id } = useParams()
  const cid = Number(id)
  const { t, i18n } = useTranslation('engagement')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const q = useQuery({ queryKey: ['challenge', cid, locale], queryFn: () => challengesApi.show(cid) })
  const [editing, setEditing] = useState(false)
  const [picked, setPicked] = useState<StudentSummary | null>(null)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error' | 'info'; text: string } | null>(null)
  const refreshAll = () => { void qc.invalidateQueries({ queryKey: ['challenge', cid] }); void qc.invalidateQueries({ queryKey: ['challenges'] }) }
  const refresh = useMutation({ mutationFn: () => challengesApi.refresh(cid), onSuccess: refreshAll })
  const enroll = useMutation({
    mutationFn: (sid: number) => challengesApi.enroll(cid, [sid]),
    onSuccess: (r) => { setPicked(null); setNotice(r.failed.length ? { tone: 'error', text: r.failed.map((f) => `${f.name}: ${f.reason}`).join('، ') } : null); refreshAll() },
  })
  const remove = useMutation({ mutationFn: () => challengesApi.remove(cid), onSuccess: () => navigate('/competitions?tab=challenges'), onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }) })
  const c = q.data
  if (q.isLoading) return <LoadingState />
  if (q.isError || !c) return <ErrorState onRetry={() => void q.refetch()} />
  const manage = can('challenges.manage')
  const d = (v: string) => formatDate(v, locale, { day: 'numeric', month: 'short' })

  return (
    <div className="space-y-5">
      <Link to="/competitions?tab=challenges" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline"><Icon name="chevron" className="size-4 ltr:rotate-180" /> {t('competitions.tab_challenges')}</Link>
      <header className="flex flex-wrap items-start gap-4">
        <div className="min-w-0 flex-1 space-y-1">
          <h1 dir="auto" className="font-display text-3xl text-ink">{c.name}</h1>
          <p dir="auto" className="text-sm text-ink/70">{goalText(c, t, locale)}</p>
          <p className="flex flex-wrap items-center gap-2 text-sm text-ink/60">
            <Badge tone={CH_TONE[c.status]}>{t(`challenges.status.${c.status}`)}</Badge>
            <span>{t('challenges.dates', { from: d(c.starts_at), to: d(c.ends_at) })}{c.status === 'active' && c.days_left !== null && ` · ${t('challenges.days_left', { n: formatNumber(c.days_left, locale) })}`}</span>
            <span>· {t('challenges.reward', { points: formatNumber(c.reward_points, locale) })}{c.reward_badge && ` ${t('challenges.reward_badge', { badge: c.reward_badge })}`}</span>
          </p>
        </div>
        {manage && (
          <div className="flex flex-wrap gap-2">
            <SecondaryButton onClick={() => refresh.mutate()} disabled={refresh.isPending}><Icon name="refresh" className="size-4" /> {t('challenges.refresh')}</SecondaryButton>
            <SecondaryButton onClick={() => setEditing(true)}><Icon name="edit" className="size-4" /> {t('competitions.actions.edit')}</SecondaryButton>
          </div>
        )}
      </header>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {c.description && <p dir="auto" className="text-sm text-ink/70">{c.description}</p>}

      <Card>
        <CardTitle actions={manage && c.status === 'active' && (
          <div className="flex min-w-64 items-end gap-2">
            <div className="flex-1"><StudentPicker label={t('challenges.enroll')} value={picked} onChange={setPicked} gender={c.gender} /></div>
            <PrimaryButton disabled={!picked} loading={enroll.isPending} onClick={() => picked && enroll.mutate(picked.id)}>+</PrimaryButton>
          </div>
        )}>{t('challenges.leaderboard')} · {t('challenges.counts', { p: formatNumber(c.participants_count ?? 0, locale), c: formatNumber(c.completed_count ?? 0, locale) })}</CardTitle>
        {c.leaderboard.length === 0 ? <EmptyState size="sm" icon="flag" title={t('challenges.no_participants')} /> : (
          <ol className="divide-y divide-ink/6">
            {c.leaderboard.map((r) => r.student && (
              <li key={r.student.id} className="flex items-center gap-3 py-2.5">
                <span className="w-7 text-center font-semibold tabular-nums text-ink/60">{formatNumber(r.position, locale)}</span>
                <Avatar name={r.student.full_name} initial={r.student.initial} src={r.student.photo_url} gender={c.gender} size="sm" />
                <div className="min-w-0 flex-1 space-y-1">
                  <div className="flex items-center gap-2"><p dir="auto" className="truncate font-medium text-ink">{r.student.full_name}</p>{r.participant_status === 'completed' && <Icon name="check" className="size-4 text-brand-600" title={t('challenges.p_status.completed')} />}</div>
                  <ProgressBar value={r.progress_pct} label={`${r.student.full_name}: ${r.progress_pct}%`} tone={r.participant_status === 'completed' ? 'gold' : 'brand'} />
                </div>
                <span className="shrink-0 text-end text-sm tabular-nums text-ink/70">{formatNumber(r.progress_value, locale)} / {formatNumber(c.goal_value, locale)}</span>
              </li>
            ))}
          </ol>
        )}
      </Card>
      {manage && (c.participants_count ?? 0) === 0 && <SecondaryButton onClick={() => remove.mutate()}><Icon name="trash" className="size-4" /> {t('competitions.actions.delete')}</SecondaryButton>}
      {editing && <ChallengeForm challenge={c} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); refreshAll() }} />}
    </div>
  )
}
