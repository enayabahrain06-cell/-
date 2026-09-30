import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { competitionsApi, type CompetitionDetail, type CompetitionRow, type JudgingSheet } from '../../api/engagement'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { EmptyState, OrnamentDivider, PageBand } from '../../components/ornaments'
import { Badge, buttonClass, Card, CardTitle, ErrorState, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, Segmented, TABLE_HEAD, TableWrap, TextInput, type Tone, inputClass, EmptyCard, SURFACE } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'
import CompetitionForm from './CompetitionForm'
import { ChallengesPanel } from './ChallengePages'
import { MEDAL } from '../honor/HonorBoardPage'
import { MobileChallengesFab, MobileCompetitionHeader, MobileCompetitionList, MobileCompetitionsTabs } from './MobileCompetitions'

export const COMP_TONE: Record<CompetitionRow['status'], Tone> = { draft: 'muted', open: 'brand', running: 'gold', judging: 'gold', finished: 'brand', cancelled: 'muted' }
const GENDER_TONE: Record<string, Tone> = { male: 'brand', female: 'gold' }

export function CompetitionsHomePage() {
  const { t } = useTranslation('engagement')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const canComp = can('competitions.view')
  const tab = (params.get('tab') === 'challenges' || !canComp) && can('challenges.view') ? 'challenges' : 'competitions'
  const [open, setOpen] = useState(false)
  const [openChallenge, setOpenChallenge] = useState(false)
  const navigate = useNavigate()

  return (
    <>
    <div className="space-y-5">
      {/* Below lg: the page header carries the title; tabs, status groups and the FAB are the mobile variants
          (mobile-only elements come before the shared ones so desktop spacing is unchanged). */}
      <MobileCompetitionsTabs show={canComp && can('challenges.view')} tab={tab} onTab={(v) => setParams(v === 'challenges' ? { tab: v } : {})} />
      <div className="hidden space-y-5 lg:block">
        <PageBand title={t('competitions.title')} subtitle={t('competitions.subtitle')}
          actions={tab === 'competitions'
            ? can('competitions.manage') && <button type="button" onClick={() => setOpen(true)} className={buttonClass('onDeep')}>+ {t('competitions.new')}</button>
            : can('challenges.manage') && <button type="button" onClick={() => setOpenChallenge(true)} className={buttonClass('onDeep')}>+ {t('challenges.new')}</button>} />
        {canComp && can('challenges.view') && (
          <Segmented name="comp-tab" label="" value={tab} onChange={(v) => setParams(v === 'challenges' ? { tab: v } : {})}
            options={[{ value: 'competitions', label: t('competitions.tab_competitions') }, { value: 'challenges', label: t('competitions.tab_challenges') }]} />
        )}
      </div>
      {tab === 'competitions' ? (
        <>
          <MobileCompetitionList canCreate={can('competitions.manage')} onCreate={() => setOpen(true)} />
          <div className="hidden lg:block"><CompetitionList /></div>
        </>
      ) : (
        <ChallengesPanel creating={openChallenge} onCloseCreate={() => setOpenChallenge(false)} />
      )}
      {open && <CompetitionForm onClose={() => setOpen(false)} onSaved={(c) => { setOpen(false); navigate(`/competitions/${c.id}`) }} />}
    </div>
    {/* Outside the spaced column: a trailing hidden element there would add bottom spacing on desktop. */}
    {tab === 'challenges' && <MobileChallengesFab canCreate={can('challenges.manage')} onCreate={() => setOpenChallenge(true)} />}
    </>
  )
}

function CompetitionList() {
  const { t, i18n } = useTranslation('engagement')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['competitions', locale], queryFn: () => competitionsApi.list({ per_page: 50 }) })
  const n = (v: number | null) => formatNumber(v ?? 0, locale)
  const d = (v: string) => formatDate(v, locale, { day: 'numeric', month: 'short' })
  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState onRetry={() => void q.refetch()} />
  if (q.data.data.length === 0) return <EmptyCard icon="trophy" title={t('competitions.empty')} body={t('competitions.empty_body')} />
  return (
    <ul className="grid gap-3 *:min-w-0 md:grid-cols-2">
      {q.data.data.map((c) => (
        <li key={c.id}>
          <Link to={`/competitions/${c.id}`} className={`${SURFACE} block h-full p-4 hover:border-brand-500/40`}>
            <div className="flex items-start justify-between gap-2">
              <p dir="auto" className="font-semibold text-ink">{c.name}</p>
              <span className="flex shrink-0 gap-1"><Badge tone={COMP_TONE[c.status]}>{t(`competitions.status.${c.status}`)}</Badge><Badge tone={GENDER_TONE[c.gender]}>{c.gender === 'female' ? t('display.girls') : t('display.boys')}</Badge></span>
            </div>
            <p className="mt-1 text-sm text-ink/60">{t(`competitions.type.${c.type}`)} · {t(`competitions.scope.${c.scope}`)} · {c.min_age || c.max_age ? t('competitions.ages', { min: n(c.min_age ?? 3), max: n(c.max_age ?? 99) }) : t('competitions.any_age')}</p>
            <p className="mt-2 text-sm text-ink/70">{t('competitions.registration', { from: d(c.registration_opens_at), to: d(c.registration_closes_at) })}</p>
            <p className="mt-1 text-xs text-ink/50">{t('competitions.counts', { p: n(c.participants_count), r: n(c.rounds_count), j: n(c.judges_count) })}</p>
          </Link>
        </li>
      ))}
    </ul>
  )
}

type Tab = 'overview' | 'participants' | 'judges' | 'judging' | 'results'
const NEXT_STATUS: Record<string, string[]> = { draft: ['open', 'cancelled'], open: ['running', 'draft', 'cancelled'], running: ['judging', 'cancelled'], judging: ['running'], finished: [], cancelled: ['draft'] }

export function CompetitionDetailPage() {
  const { id } = useParams()
  const cid = Number(id)
  const { t, i18n } = useTranslation('engagement')
  const locale = i18n.language
  const qc = useQueryClient()
  const navigate = useNavigate()
  const q = useQuery({ queryKey: ['competition', cid, locale], queryFn: () => competitionsApi.show(cid) })
  const c = q.data
  const [tab, setTab] = useState<Tab>('overview')
  const [editing, setEditing] = useState(false)
  const [publishOpen, setPublishOpen] = useState(false)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['competition', cid] }); void qc.invalidateQueries({ queryKey: ['competitions'] }) }
  const fail = (e: unknown) => setNotice({ tone: 'error', text: parseApiError(e).message })
  const status = useMutation({ mutationFn: (s: string) => competitionsApi.status(cid, s), onSuccess: refresh, onError: fail })
  const remove = useMutation({ mutationFn: () => competitionsApi.remove(cid), onSuccess: () => navigate('/competitions'), onError: fail })

  useEffect(() => { if (c && !c.can.manage && c.can.judge) setTab('judging') }, [c])

  if (q.isLoading) return <><MobileCompetitionHeader /><LoadingState /></>
  if (q.isError || !c) return <><MobileCompetitionHeader /><ErrorState onRetry={() => void q.refetch()} /></>
  const tabs: Tab[] = c.can.manage ? ['overview', 'participants', 'judges', 'judging', 'results'] : ['overview', 'judging']
  const d = (v: string) => formatDate(v, locale, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })

  return (
    <div className="space-y-5">
      <MobileCompetitionHeader name={c.name} />
      <Link to="/competitions" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline"><Icon name="chevron" className="size-4 ltr:rotate-180" /> {t('competitions.title')}</Link>
      <header className="flex flex-wrap items-start gap-4">
        <div className="min-w-0 flex-1 space-y-1">
          <h1 dir="auto" className="font-display text-3xl text-ink">{c.name}</h1>
          <p className="flex flex-wrap items-center gap-2 text-sm text-ink/60">
            <Badge tone={COMP_TONE[c.status]}>{t(`competitions.status.${c.status}`)}</Badge>
            <Badge tone={GENDER_TONE[c.gender]}>{c.gender === 'female' ? t('display.girls') : t('display.boys')}</Badge>
            <span>{t(`competitions.type.${c.type}`)} · {t(`competitions.scope.${c.scope}`)}</span>
          </p>
          <p className="text-xs text-ink/50">{c.published ? t('competitions.published_note') : t('competitions.hidden_note')}</p>
        </div>
        {c.can.manage && !c.published && (
          <div className="flex flex-wrap gap-2">
            {NEXT_STATUS[c.status].map((s) => <SecondaryButton key={s} disabled={status.isPending} onClick={() => status.mutate(s)}>{t(`competitions.actions.${s}`)}</SecondaryButton>)}
            <SecondaryButton onClick={() => setEditing(true)}><Icon name="edit" className="size-4" /> {t('competitions.actions.edit')}</SecondaryButton>
          </div>
        )}
      </header>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <Segmented name="comp-detail-tab" label="" value={tab} onChange={setTab} options={tabs.map((v) => ({ value: v, label: t(`competitions.tabs.${v}`) }))} />

      {tab === 'overview' && (
        <div className="grid gap-4 lg:grid-cols-3">
          <Card className="lg:col-span-2">
            <CardTitle>{t('competitions.rounds')}</CardTitle>
            <ol className="space-y-2">
              {c.rounds.map((r) => (
                <li key={r.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-ink/8 px-3 py-2 text-sm">
                  <span className="grid size-7 place-items-center rounded-full bg-brand-50 font-semibold text-brand-700 tabular-nums">{formatNumber(r.sort_order, locale)}</span>
                  <span dir="auto" className="font-medium text-ink">{r.name}</span>
                  <span className="text-ink/60">{formatDate(r.round_date, locale, { weekday: 'short', day: 'numeric', month: 'short' })}{r.start_time && ` · ${r.start_time}`}</span>
                  {r.location && <span dir="auto" className="ms-auto text-ink/50"><Icon name="pin" className="inline size-4" /> {r.location}</span>}
                </li>
              ))}
            </ol>
            <OrnamentDivider className="my-4 text-gold-500" />
            <CardTitle>{t('competitions.criteria')}</CardTitle>
            <ul className="grid gap-2 *:min-w-0 sm:grid-cols-2">
              {c.criteria.map((cr) => (
                <li key={cr.key} className="flex items-center justify-between rounded-xl bg-page/60 px-3 py-2 text-sm">
                  <span dir="auto">{locale === 'en' && cr.name_en ? cr.name_en : cr.name_ar}</span>
                  <span className="tabular-nums text-ink/60">{formatNumber(cr.weight, locale)}٪ · /{formatNumber(cr.max, locale)}</span>
                </li>
              ))}
            </ul>
            <p className="mt-2 text-xs text-ink/50">{t('form.tie_break')}: {t(`competitions.tie_break.${c.tie_break}`)}</p>
          </Card>
          <div className="space-y-4">
            <Card>
              <CardTitle>{t('form.steps.eligibility')}</CardTitle>
              <dl className="space-y-1.5 text-sm">
                <div className="flex justify-between gap-2"><dt className="text-ink/60">{t('form.opens')}</dt><dd>{d(c.registration_opens_at)}</dd></div>
                <div className="flex justify-between gap-2"><dt className="text-ink/60">{t('form.closes')}</dt><dd>{d(c.registration_closes_at)}</dd></div>
                <div className="flex justify-between gap-2"><dt className="text-ink/60">{t('form.starts')}</dt><dd>{d(c.starts_at)}</dd></div>
                <div className="flex justify-between gap-2"><dt className="text-ink/60">{t('form.scope')}</dt><dd dir="auto">{c.scope_lesson?.name ?? c.scope_package?.name ?? t(`competitions.scope.${c.scope}`)}</dd></div>
                <div className="flex justify-between gap-2"><dt className="text-ink/60">{t('form.min_age')}</dt><dd>{c.min_age || c.max_age ? t('competitions.ages', { min: formatNumber(c.min_age ?? 3, locale), max: formatNumber(c.max_age ?? 99, locale) }) : t('competitions.any_age')}</dd></div>
                <div className="flex justify-between gap-2"><dt className="text-ink/60">{t('competitions.participants')}</dt><dd className="tabular-nums">{formatNumber(c.participants_count ?? 0, locale)}{c.max_participants ? ` / ${formatNumber(c.max_participants, locale)}` : ''}</dd></div>
              </dl>
            </Card>
            <Card>
              <CardTitle>{t('competitions.prizes')}</CardTitle>
              <ul className="space-y-2 text-sm">
                {c.prizes.map((p) => (
                  <li key={p.id} className="flex items-center gap-2"><Icon name="medal" className={`size-5 ${MEDAL[Math.min(p.rank, 3) - 1]}`} /><span dir="auto" className="flex-1">{p.title}</span>{p.points > 0 && <Badge tone="gold">+{formatNumber(p.points, locale)}</Badge>}</li>
                ))}
              </ul>
            </Card>
            {c.can.manage && c.status === 'draft' && <SecondaryButton className="w-full" onClick={() => window.confirm(t('competitions.delete_confirm')) && remove.mutate()}><Icon name="trash" className="size-4" /> {t('competitions.actions.delete')}</SecondaryButton>}
          </div>
        </div>
      )}
      {tab === 'participants' && <ParticipantsTab c={c} onChange={refresh} />}
      {tab === 'judges' && <JudgesTab c={c} onChange={refresh} />}
      {tab === 'judging' && <JudgingTab c={c} />}
      {tab === 'results' && <ResultsTab c={c} onPublish={() => setPublishOpen(true)} />}

      {editing && <CompetitionForm competition={c} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); refresh() }} />}
      {publishOpen && <PublishDialog c={c} onClose={() => setPublishOpen(false)} onDone={(text) => { setPublishOpen(false); setNotice({ tone: 'success', text }); refresh(); void qc.invalidateQueries({ queryKey: ['standings', cid] }) }} />}
    </div>
  )
}

function ParticipantsTab({ c, onChange }: { c: CompetitionDetail; onChange: () => void }) {
  const { t, i18n } = useTranslation('engagement')
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['competition-participants', c.id], queryFn: () => competitionsApi.participants(c.id) })
  const [adding, setAdding] = useState(false)
  const withdraw = useMutation({ mutationFn: (pid: number) => competitionsApi.withdraw(c.id, pid), onSuccess: () => { void qc.invalidateQueries({ queryKey: ['competition-participants', c.id] }); onChange() } })
  const active = (q.data ?? []).filter((p) => p.status !== 'withdrawn')
  return (
    <Card>
      <CardTitle actions={!c.published && <PrimaryButton onClick={() => setAdding(true)}>+ {t('competitions.add_participants')}</PrimaryButton>}>{t('competitions.participants')} ({formatNumber(active.length, i18n.language)})</CardTitle>
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : active.length === 0 ? <EmptyState size="sm" icon="students" title={t('competitions.no_participants')} /> : (
        <ul className="divide-y divide-ink/6">
          {active.map((p) => p.student && (
            <li key={p.id} className="flex items-center gap-3 py-2">
              <Avatar name={p.student.full_name} initial={p.student.full_name.charAt(0)} src={p.student.photo_url} gender={p.student.gender as 'male' | 'female'} size="sm" />
              <div className="min-w-0 flex-1"><p dir="auto" className="truncate font-medium text-ink">{p.student.full_name}</p><p dir="auto" className="truncate text-xs text-ink/50">{p.student.circle?.name ?? p.student.student_no}</p></div>
              {p.final_rank && <Badge tone="gold">{t('honor.place', { n: formatNumber(p.final_rank, i18n.language) })}</Badge>}
              {!c.published && <SecondaryButton onClick={() => withdraw.mutate(p.id)} disabled={withdraw.isPending}>{t('competitions.withdraw')}</SecondaryButton>}
            </li>
          ))}
        </ul>
      )}
      {adding && <AddParticipantsDialog c={c} onClose={() => setAdding(false)} onDone={() => { setAdding(false); void qc.invalidateQueries({ queryKey: ['competition-participants', c.id] }); onChange() }} />}
    </Card>
  )
}

function AddParticipantsDialog({ c, onClose, onDone }: { c: CompetitionDetail; onClose: () => void; onDone: () => void }) {
  const { t, i18n } = useTranslation('engagement')
  const [search, setSearch] = useState('')
  const [picked, setPicked] = useState<number[]>([])
  const [result, setResult] = useState<string | null>(null)
  const q = useQuery({ queryKey: ['competition-candidates', c.id, search], queryFn: () => competitionsApi.candidates(c.id, search || undefined) })
  const save = useMutation({
    mutationFn: () => competitionsApi.register(c.id, picked),
    onSuccess: (r) => { if (r.failed.length) setResult(`${t('competitions.registered_n', { n: r.registered })} · ${t('competitions.failed_n', { n: r.failed.length })}: ${r.failed.map((f) => `${f.name} (${f.reason})`).join('، ')}`); else onDone() },
  })
  const toggle = (id: number) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))
  return (
    <Modal wide title={t('competitions.add_participants')} onClose={onClose}
      footer={<><SecondaryButton onClick={result ? onDone : onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton disabled={!picked.length} loading={save.isPending} onClick={() => save.mutate()}>{t('competitions.register_selected', { n: formatNumber(picked.length, i18n.language) })}</PrimaryButton></>}>
      {result && <Notice tone="info">{result}</Notice>}
      <TextInput label={t('competitions.search')} value={search} onChange={(e) => setSearch(e.target.value)} />
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : (
        <ul className="max-h-96 divide-y divide-ink/6 overflow-y-auto rounded-xl border border-ink/10">
          {(q.data ?? []).map((row) => (
            <li key={row.student.id}>
              <label className={`flex items-center gap-3 px-3 py-2 text-sm ${row.eligible && !row.registered ? 'cursor-pointer' : 'opacity-60'}`}>
                <input type="checkbox" className="size-4 accent-brand-600" disabled={!row.eligible || row.registered} checked={picked.includes(row.student.id)} onChange={() => toggle(row.student.id)} />
                <span dir="auto" className="min-w-0 flex-1 truncate text-ink">{row.student.full_name}<span className="block text-xs text-ink/50">{row.student.circle?.name ?? row.student.student_no}</span></span>
                {row.registered ? <Badge tone="brand">{t('competitions.registered')}</Badge> : row.eligible ? <Badge tone="muted">{t('competitions.eligible')}</Badge> : <Badge tone="gold">{t(`competitions.reason.${row.reason}`)}</Badge>}
              </label>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  )
}

function JudgesTab({ c, onChange }: { c: CompetitionDetail; onChange: () => void }) {
  const { t } = useTranslation('engagement')
  const cand = useQuery({ queryKey: ['judge-candidates', c.id], queryFn: () => competitionsApi.judgeCandidates(c.id) })
  const [userId, setUserId] = useState('')
  const [roundId, setRoundId] = useState('')
  const [error, setError] = useState<string | null>(null)
  const add = useMutation({ mutationFn: () => competitionsApi.addJudge(c.id, Number(userId), roundId ? Number(roundId) : null), onSuccess: () => { setUserId(''); onChange() }, onError: (e) => setError(parseApiError(e).message) })
  const remove = useMutation({ mutationFn: (jid: number) => competitionsApi.removeJudge(c.id, jid), onSuccess: onChange })
  const roundName = (id: number | null) => (id ? c.rounds.find((r) => r.id === id)?.name : t('competitions.all_rounds'))
  return (
    <Card>
      <CardTitle>{t('competitions.judges')}</CardTitle>
      <p className="mb-3 text-xs text-ink/55">{t('competitions.judge_hint', { track: c.gender === 'female' ? t('display.girls') : t('display.boys') })}</p>
      {error && <Notice tone="error">{error}</Notice>}
      {!c.published && (
        <div className="mb-4 flex flex-wrap items-end gap-2">
          <div className="min-w-48 flex-1"><SelectField label={t('competitions.add_judge')} value={userId} onChange={(e) => setUserId(e.target.value)} options={[{ value: '', label: '—' }, ...(cand.data ?? []).map((u) => ({ value: String(u.id), label: u.name }))]} /></div>
          <div className="min-w-40"><SelectField label={t('competitions.round')} value={roundId} onChange={(e) => setRoundId(e.target.value)} options={[{ value: '', label: t('competitions.all_rounds') }, ...c.rounds.map((r) => ({ value: String(r.id), label: r.name }))]} /></div>
          <PrimaryButton disabled={!userId} loading={add.isPending} onClick={() => add.mutate()}>+ {t('competitions.add_judge')}</PrimaryButton>
        </div>
      )}
      {c.judges.length === 0 ? <EmptyState size="sm" icon="teachers" title={t('competitions.no_judges')} /> : (
        <ul className="divide-y divide-ink/6">
          {c.judges.map((j) => (
            <li key={j.id} className="flex items-center gap-3 py-2 text-sm">
              <Avatar name={j.name} size="sm" gender={c.gender} />
              <span dir="auto" className="flex-1 font-medium text-ink">{j.name}</span>
              <Badge tone="muted">{roundName(j.round_id)}</Badge>
              {!c.published && <button type="button" onClick={() => remove.mutate(j.id)} className="rounded p-1 text-ink/40 hover:text-danger" aria-label={t('form.remove')}><Icon name="trash" className="size-4" /></button>}
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function JudgingTab({ c }: { c: CompetitionDetail }) {
  const { t } = useTranslation('engagement')
  const [roundId, setRoundId] = useState<number>(c.rounds[0]?.id ?? 0)
  const q = useQuery({ queryKey: ['judge-sheet', c.id, roundId], queryFn: () => competitionsApi.sheet(c.id, roundId), enabled: !!roundId, retry: false })
  return (
    <Card>
      <CardTitle actions={<div className="min-w-44"><SelectField label={t('competitions.round')} hideLabel value={roundId} onChange={(e) => setRoundId(Number(e.target.value))} options={c.rounds.map((r) => ({ value: String(r.id), label: r.name }))} /></div>}>{t('competitions.tabs.judging')}</CardTitle>
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <Notice tone="info">{t('competitions.not_judge')}</Notice> : <JudgingSheetView sheet={q.data} competitionId={c.id} />}
    </Card>
  )
}

function JudgingSheetView({ sheet, competitionId }: { sheet: JudgingSheet; competitionId: number }) {
  const { t, i18n } = useTranslation('engagement')
  const locale = i18n.language
  if (sheet.participants.length === 0) return <EmptyState size="sm" icon="students" title={t('competitions.no_participants')} />
  return (
    <div className="space-y-3">
      {sheet.locked && <Notice tone="info">{t('competitions.locked')}</Notice>}
      {sheet.participants.map((p) => <ScoreRow key={p.id} p={p} sheet={sheet} competitionId={competitionId} locale={locale} />)}
    </div>
  )
}

function ScoreRow({ p, sheet, competitionId, locale }: { p: JudgingSheet['participants'][number]; sheet: JudgingSheet; competitionId: number; locale: string }) {
  const { t } = useTranslation('engagement')
  const qc = useQueryClient()
  const [scores, setScores] = useState<Record<string, number | ''>>(() => Object.fromEntries(sheet.criteria.map((c) => [c.key, p.scores?.[c.key] ?? ''])))
  const [note, setNote] = useState(p.note ?? '')
  const [error, setError] = useState<string | null>(null)
  const total = useMemo(() => sheet.criteria.reduce((s, c) => s + ((Number(scores[c.key]) || 0) / c.max) * c.weight, 0), [scores, sheet.criteria])
  const complete = sheet.criteria.every((c) => scores[c.key] !== '')
  const save = useMutation({
    mutationFn: () => competitionsApi.score(competitionId, sheet.round.id, { participant_id: p.id, scores: scores as Record<string, number>, note: note || undefined }),
    onSuccess: () => { setError(null); void qc.invalidateQueries({ queryKey: ['judge-sheet', competitionId, sheet.round.id] }) },
    onError: (e) => { const x = parseApiError(e); setError(Object.values(x.fields)[0]?.[0] ?? x.message) },
  })
  return (
    <div className="rounded-2xl border border-ink/10 p-3">
      <div className="mb-2 flex items-center gap-3">
        <Avatar name={p.student.full_name} initial={p.student.initial} src={p.student.photo_url} size="sm" />
        <p dir="auto" className="flex-1 font-semibold text-ink">{p.student.full_name}</p>
        <span className="text-sm text-ink/60">{t('competitions.total')} <span className="text-lg font-semibold tabular-nums text-brand-700">{formatNumber(total, locale, { maximumFractionDigits: 1 })}</span></span>
        {p.total !== null && !save.isPending && <Badge tone="brand"><Icon name="check" className="size-3.5" /> {t('competitions.saved')}</Badge>}
      </div>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        {sheet.criteria.map((c) => (
          <label key={c.key} className="text-xs text-ink/60">
            <span dir="auto" className="mb-1 block">{locale === 'en' && c.name_en ? c.name_en : c.name_ar} <span className="tabular-nums">({formatNumber(c.weight, locale)}٪)</span></span>
            <input type="number" inputMode="numeric" min={0} max={c.max} disabled={sheet.locked} value={scores[c.key]} onChange={(e) => setScores({ ...scores, [c.key]: e.target.value === '' ? '' : Math.max(0, Math.min(c.max, Number(e.target.value))) })}
              className={inputClass('sm', 'w-full text-center text-base tabular-nums')} aria-describedby={`max-${p.id}-${c.key}`} />
            <span id={`max-${p.id}-${c.key}`} className="sr-only">/ {c.max}</span>
          </label>
        ))}
      </div>
      <div className="mt-2 flex flex-wrap items-end gap-2">
        <div className="min-w-48 flex-1"><TextInput label={t('competitions.note')} hideLabel placeholder={t('competitions.note')} disabled={sheet.locked} value={note} onChange={(e) => setNote(e.target.value)} dir="auto" /></div>
        <PrimaryButton disabled={!complete || sheet.locked} loading={save.isPending} onClick={() => save.mutate()}>{t('competitions.save_score')}</PrimaryButton>
      </div>
    </div>
  )
}

function ResultsTab({ c, onPublish }: { c: CompetitionDetail; onPublish: () => void }) {
  const { t, i18n } = useTranslation('engagement')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['standings', c.id], queryFn: () => competitionsApi.standings(c.id) })
  const n = (v: number | null | undefined, d = 1) => (v === null || v === undefined ? '—' : formatNumber(v, locale, { maximumFractionDigits: d }))
  return (
    <Card>
      <CardTitle actions={!c.published && <PrimaryButton onClick={onPublish} disabled={!q.data?.some((r) => r.final !== null)}><Icon name="trophy" className="size-4" /> {t('competitions.actions.publish')}</PrimaryButton>}>{t('competitions.standings')}</CardTitle>
      <p className="mb-3 text-xs text-ink/55">{c.published ? t('competitions.published_note', { when: '' }) : t('competitions.hidden_note')}</p>
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : !q.data || q.data.length === 0 ? <EmptyState size="sm" icon="trophy" title={t('competitions.no_scores')} /> : (
        <TableWrap>
          <table className="w-full text-sm">
            <thead className={TABLE_HEAD}>
              <tr>
                <th className="px-4 py-3 text-start font-medium">{t('honor.rank')}</th>
                <th className="px-4 py-3 text-start font-medium">{t('honor.student')}</th>
                {c.rounds.map((r) => <th key={r.id} className="hidden px-4 py-3 text-start font-medium sm:table-cell" dir="auto">{r.name}</th>)}
                <th className="px-4 py-3 text-start font-medium">{t('competitions.final')}</th>
                <th className="hidden px-4 py-3 text-start font-medium md:table-cell">{t('competitions.judged')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink/6">
              {q.data.map((r) => (
                <tr key={r.participant_id}>
                  <td className="px-4 py-3 font-semibold tabular-nums">{r.rank !== null && r.rank <= 3 && <Icon name="medal" className={`inline size-4 ${MEDAL[r.rank - 1]}`} />} {r.rank !== null ? formatNumber(r.rank, locale) : '—'}</td>
                  <td className="px-4 py-3"><span dir="auto">{r.student.full_name}</span></td>
                  {c.rounds.map((rd) => <td key={rd.id} className="hidden px-4 py-3 tabular-nums sm:table-cell">{n(r.rounds[String(rd.id)])}</td>)}
                  <td className="px-4 py-3 font-semibold tabular-nums text-brand-700">{n(r.final)}</td>
                  <td className="hidden px-4 py-3 tabular-nums text-ink/60 md:table-cell">{formatNumber(r.judged, locale)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableWrap>
      )}
    </Card>
  )
}

function PublishDialog({ c, onClose, onDone }: { c: CompetitionDetail; onClose: () => void; onDone: (text: string) => void }) {
  const { t } = useTranslation('engagement')
  const [notify, setNotify] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const run = useMutation({ mutationFn: () => competitionsApi.publish(c.id, notify), onSuccess: () => onDone(t('competitions.published_note', { when: '' })), onError: (e) => setError(parseApiError(e).message) })
  return (
    <Modal title={t('competitions.publish_title')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton loading={run.isPending} onClick={() => run.mutate()}>{t('competitions.actions.publish')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      <p className="text-sm text-ink/70">{t('competitions.publish_body')}</p>
      <label className="flex items-center gap-2 text-sm text-ink/80"><input type="checkbox" className="size-4 accent-brand-600" checked={notify} onChange={(e) => setNotify(e.target.checked)} />{t('competitions.notify')}</label>
    </Modal>
  )
}
