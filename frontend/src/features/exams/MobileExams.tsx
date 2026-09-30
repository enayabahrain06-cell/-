import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { Exam } from '../../api/exams'
import { Fab } from '../../components/mobile/ActionBars'
import { Chip, ChipRow, MCard, MEmpty, MSegmented, Pill, Skeleton, M_BTN_SECONDARY, M_CARD, type PillTone } from '../../components/mobile/atoms'
import { formatDate, formatNumber } from '../../lib/format'

/** Exams below lg (mobile-redesign-spec.md §6.13). The query, URL filters and the create dialog stay in ExamsHomePage. */

const STATUS_TONE: Record<string, PillTone> = { draft: 'neutral', published: 'ok', closed: 'warn', graded: 'info' }
/** The spec's three tabs map onto the existing `status` filter; "all" keeps drafts reachable. */
const SEGMENTS = ['', 'published', 'closed', 'graded'] as const
const DAY = 86_400_000

const bahrainDay = (d: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bahrain' }).format(d)

export default function MobileExams({ exams, loading, error, onRetry, filters, onSet, canCreate, onCreate, pagination }: {
  exams: Exam[] | undefined; loading: boolean; error: boolean; onRetry: () => void
  filters: { type?: string; status?: string }; onSet: (k: string, v: string) => void
  canCreate: boolean; onCreate: () => void; pagination: ReactNode
}) {
  const { t, i18n } = useTranslation('exams')
  const locale = i18n.language
  const status = (SEGMENTS as readonly string[]).includes(filters.status ?? '') ? (filters.status ?? '') : ''

  return (
    <div className="space-y-3 lg:hidden">
      <MSegmented label={t('filters.all_statuses')} value={status} onChange={(v) => onSet('status', v)}
        options={SEGMENTS.map((s) => ({ value: s, label: t(`mobile.segments.${s || 'all'}`) }))} />
      <ChipRow label={t('filters.all_types')}>
        <Chip active={!filters.type} onClick={() => onSet('type', '')}>{t('filters.all_types')}</Chip>
        {(['online', 'paper', 'placement'] as const).map((k) => <Chip key={k} active={filters.type === k} onClick={() => onSet('type', k)}>{t(`type.${k}`)}</Chip>)}
      </ChipRow>

      {loading ? <ExamsSkeleton /> : error ? (
        <MCard><MEmpty icon="alert" text={t('mobile.error')} action={<button type="button" onClick={onRetry} className={M_BTN_SECONDARY}>{t('mobile.retry')}</button>} /></MCard>
      ) : !exams || exams.length === 0 ? (
        <MCard><MEmpty icon="exams" text={t('empty')} /></MCard>
      ) : (
        <ul className="space-y-3">{exams.map((e) => <ExamCard key={e.id} exam={e} locale={locale} />)}</ul>
      )}
      {pagination}
      {canCreate && <Fab label={t('new')} onClick={onCreate} />}
    </div>
  )
}

function ExamCard({ exam: e, locale }: { exam: Exam; locale: string }) {
  const { t } = useTranslation('exams')
  const n = (v: number) => formatNumber(v, locale)
  const when = e.type === 'paper' ? e.exam_date : e.opens_at
  const pending = e.status === 'closed'
  const placement = e.type === 'placement'

  // Countdown: open now, today, or in n days (published exams that have not started yet).
  let countdown: { tone: PillTone; text: string } | null = null
  if (e.status === 'published') {
    if (e.is_open_now) countdown = { tone: 'err', text: t('open_now') }
    else {
      const days = Math.round((Date.parse(bahrainDay(new Date(when))) - Date.parse(bahrainDay(new Date()))) / DAY)
      if (days === 0) countdown = { tone: 'warn', text: t('mobile.today') }
      else if (days > 0) countdown = { tone: 'neutral', text: t('mobile.in_days', { count: days, n: n(days) }) }
    }
  }

  return (
    <li className={`${pending ? 'rounded-card border border-gold-500/40 bg-gold-500/12 shadow-card' : M_CARD} p-4`}>
      <div className="flex items-start gap-3">
        {/* Date block: day number and short month (sans, tabular), the exam day or the online window's start. */}
        <div className={`flex w-12 shrink-0 flex-col items-center rounded-ctl py-1.5 ${pending ? 'bg-white' : 'bg-brand-50'}`}>
          <span className="text-xl font-semibold leading-7 tabular-nums text-brand-700">{formatDate(when, locale, { day: 'numeric' })}</span>
          <span className="text-xs text-ink/65">{formatDate(when, locale, { month: 'short' })}</span>
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-start justify-between gap-2">
            <Link to={`/exams/${e.id}`} className="min-w-0 text-[15px] font-semibold text-ink"><bdi>{e.name}</bdi></Link>
            <Pill tone={STATUS_TONE[e.status]}>{t(`status.${e.status}`)}</Pill>
          </div>
          <p className="mt-1 truncate text-[13px] text-ink/65">
            {e.lesson_id && e.lesson_name ? <Link to={`/lessons/${e.lesson_id}`} className="text-info"><bdi>{e.lesson_name}</bdi></Link> : <bdi>{e.lesson_name ?? e.package_name ?? '—'}</bdi>}
            {' · '}<span className="tabular-nums">{t('attempts_n', { n: n(e.attempts_count ?? 0) })}</span>
          </p>
          <p className="mt-0.5 text-[13px] text-ink/65">
            {t(`type.${e.type}`)} · <span className="tabular-nums">{placement ? t('placement.total_marks', { n: n(e.total_marks) }) : t('marks', { pass: n(e.pass_mark), total: n(e.total_marks) })}</span>
          </p>
          {countdown && <div className="mt-2"><Pill tone={countdown.tone}>{countdown.text}</Pill></div>}
        </div>
      </div>
      <div className="mt-3 grid grid-cols-2 gap-2">
        {/* Awaiting grading: the grading tab is where results are entered, so it replaces the roster button. */}
        {pending && !placement
          ? <Link to={`/exams/${e.id}?tab=grading`} className={`${M_BTN_SECONDARY} border-gold-500/40 px-3 text-[13px]`}>{t('mobile.enter_results')}</Link>
          : <Link to={`/exams/${e.id}?tab=${placement ? 'results' : 'grading'}`} className={`${M_BTN_SECONDARY} px-3 text-[13px]`}>{t('mobile.students')}</Link>}
        <Link to={`/exams/${e.id}?tab=${e.status === 'draft' ? 'questions' : 'results'}`} className={`${M_BTN_SECONDARY} px-3 text-[13px]`}>{e.status === 'draft' ? t('mobile.questions') : t('mobile.results')}</Link>
      </div>
    </li>
  )
}

function ExamsSkeleton() {
  return (
    <ul aria-hidden className="space-y-3">
      {[0, 1, 2].map((k) => (
        <li key={k} className={`${M_CARD} p-4`}>
          <div className="flex gap-3">
            <Skeleton className="h-14 w-12" />
            <span className="flex-1 space-y-2"><Skeleton className="h-4 w-2/3" /><Skeleton className="h-3 w-1/2" /><Skeleton className="h-3 w-1/3" /></span>
          </div>
          <div className="mt-3 grid grid-cols-2 gap-2"><Skeleton className="h-11" /><Skeleton className="h-11" /></div>
        </li>
      ))}
    </ul>
  )
}
