import { useState, type ReactNode } from 'react'
import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import type { AttendanceStatus } from '../../api/attendance'
import type { PortalCard } from '../../api/portal'
import { studentsApi } from '../../api/students'
import { Chip, ChipRow, M_BTN_SECONDARY, MEmpty, MList, MListSkeleton, MSection, Pill } from '../../components/mobile/atoms'
import { formatDate, formatPercent } from '../../lib/format'
import StudentCertificatesTab from '../certificates/StudentCertificatesTab'
import JuzMap from '../students/profile/JuzMap'
import TrendChart from '../students/profile/TrendChart'
import PortalLayout from './PortalLayout'
import { ATTENDANCE_TONE, firstName, num, usePortalRole, useFamilyStudent } from './hooks'
import { CardSkeleton, DualDate, KpiTile, PortalError, StudentStrip } from './shared'

/**
 * Frame for one student's inner page (attendance, memorization, certificates): back to the family home, title,
 * breadcrumb, the student strip, and loading / error / not-in-family states.
 */
function ChildPage({ titleKey, children }: { titleKey: string; children: (card: PortalCard) => ReactNode }) {
  const { t } = useTranslation('portal')
  const role = usePortalRole()
  const { card, loading, error, refetch, home } = useFamilyStudent()
  const title = t(titleKey)
  const homeLabel = role === 'student' ? t('tabs.progress') : t('tabs.children')
  const crumbs = [{ label: homeLabel, to: home }, ...(card && role === 'guardian' ? [{ label: firstName(card.student.full_name) }] : []), { label: title }]

  return (
    <PortalLayout title={title} back={home} breadcrumb={crumbs}>
      {error ? <PortalError onRetry={refetch} /> : loading ? <CardSkeleton /> : !card ? (
        <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty text={t('not_found')} /></div>
      ) : (
        <div className="space-y-6">
          <StudentStrip card={card} />
          {children(card)}
        </div>
      )}
    </PortalLayout>
  )
}

const STATUSES: AttendanceStatus[] = ['present', 'late', 'absent', 'excused']

export function ChildAttendancePage() {
  return <ChildPage titleKey="attendance.title">{(card) => <AttendanceBody card={card} />}</ChildPage>
}

function AttendanceBody({ card }: { card: PortalCard }) {
  const { t, i18n } = useTranslation('portal')
  const locale = i18n.language
  const [status, setStatus] = useState<AttendanceStatus | ''>('')
  const q = useInfiniteQuery({
    queryKey: ['portal-attendance', card.student.id, status, locale],
    queryFn: ({ pageParam }) => studentsApi.attendance(card.student.id, pageParam, status || undefined),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
  })
  const totals = q.data?.pages[0]?.totals
  const rows = q.data?.pages.flatMap((p) => p.data) ?? []

  return (
    <>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <KpiTile label={t('attendance.rate')} value={formatPercent(totals?.percent ?? card.kpis.attendance_percent, locale)} />
        <KpiTile label={t('status.present')} value={totals ? num(totals.present, locale) : '—'} />
        <KpiTile label={t('status.late')} value={totals ? num(totals.late, locale) : '—'} />
        <KpiTile label={t('status.absent')} value={totals ? num(totals.absent, locale) : '—'} sub={totals && totals.excused > 0 ? t('attendance.excused_n', { n: num(totals.excused, locale) }) : undefined} />
      </div>

      <MSection title={t('attendance.history')}>
        <ChipRow label={t('attendance.filter')}>
          <Chip active={status === ''} onClick={() => setStatus('')}>{t('all')}</Chip>
          {STATUSES.map((s) => <Chip key={s} active={status === s} onClick={() => setStatus(s)}>{t(`status.${s}`)}</Chip>)}
        </ChipRow>
        {q.isError ? <PortalError onRetry={() => void q.refetch()} /> : q.isLoading ? <MListSkeleton rows={6} /> : rows.length === 0 ? (
          <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty icon="attendance" text={t('attendance.empty')} /></div>
        ) : (
          <MList label={t('attendance.history')}>
            {rows.map((r) => (
              <li key={r.id} className="flex min-h-16 items-center gap-3 px-4 py-2.5">
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[15px] font-semibold text-ink"><DualDate value={r.date} /></span>
                  <span className="mt-0.5 block truncate text-[13px] text-ink/65">
                    {r.lesson && <bdi>{r.lesson}</bdi>}
                    {r.memorization_assignment && <> · {t('attendance.assignment', { text: r.memorization_assignment })}</>}
                  </span>
                  {r.note && <span className="mt-0.5 block text-[13px] text-ink/65" dir="auto">{r.note}</span>}
                </span>
                <Pill tone={ATTENDANCE_TONE[r.status]}>{t(`status.${r.status}`)}</Pill>
              </li>
            ))}
          </MList>
        )}
        {q.hasNextPage && (
          <button type="button" onClick={() => void q.fetchNextPage()} disabled={q.isFetchingNextPage} className={`${M_BTN_SECONDARY} w-full`}>
            {q.isFetchingNextPage ? t('loading') : t('load_more')}
          </button>
        )}
      </MSection>
    </>
  )
}

export function ChildMemorizationPage() {
  return <ChildPage titleKey="memorization.title">{(card) => <MemorizationBody card={card} />}</ChildPage>
}

function MemorizationBody({ card }: { card: PortalCard }) {
  const { t, i18n } = useTranslation('portal')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['student-profile', card.student.id, locale], queryFn: () => studentsApi.profile(card.student.id) })
  const p = q.data?.data

  if (q.isError) return <PortalError onRetry={() => void q.refetch()} />
  if (!p) return <div className="space-y-3"><CardSkeleton lines={2} /><CardSkeleton lines={4} /></div>

  const pos = p.header.position
  const ev = p.evaluation
  return (
    <>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiTile label={t('kpi.memorized_ayahs')} value={num(p.progress.memorized_ayahs, locale)} sub={t('memorization.quran_share', { percent: formatPercent(p.progress.quran_percent, locale) })} />
        <KpiTile label={t('kpi.completed_juz')} value={num(p.progress.completed_juz, locale)} />
        <KpiTile label={t('kpi.plan')} value={formatPercent(p.progress.plan.percent, locale)}
          sub={p.progress.plan.target_ayahs > 0 ? t('memorization.plan_of', { n: num(p.progress.plan.memorized_in_period, locale), of: num(p.progress.plan.target_ayahs, locale) }) : undefined} />
        <KpiTile label={t('kpi.rank')} value={ev.rank?.rank ? t('memorization.rank_of', { n: num(ev.rank.rank, locale), of: num(ev.rank.of, locale) }) : '—'} />
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="rounded-card border border-ink/10 bg-white p-4 shadow-card">
          <p className="text-xs font-semibold text-ink/65">{t('memorization.current')}</p>
          <p className="mt-1 text-[15px] font-semibold text-ink">{pos.current ? t('memorization.point', { surah: pos.current.surah_name, ayah: num(pos.current.ayah, locale), juz: num(pos.current.juz, locale) }) : t('memorization.not_started')}</p>
        </div>
        <div className="rounded-card border border-ink/10 bg-white p-4 shadow-card">
          <p className="text-xs font-semibold text-ink/65">{t('memorization.next')}</p>
          <p className="mt-1 text-[15px] font-semibold text-ink">{pos.next ? t('memorization.point', { surah: pos.next.surah_name, ayah: num(pos.next.ayah, locale), juz: num(pos.next.juz, locale) }) : t('memorization.khatm')}</p>
        </div>
      </div>

      {ev.latest_note && (
        <figure className="rounded-card border border-ink/10 bg-white p-4 shadow-card">
          <p className="mb-1 text-xs font-semibold text-ink/65">{t('memorization.teacher_note')}</p>
          <blockquote className="text-[15px] leading-6 text-ink" dir="auto">{ev.latest_note.note}</blockquote>
          <figcaption className="mt-1 text-[13px] text-ink/65">{ev.latest_note.teacher && <><bdi>{ev.latest_note.teacher}</bdi> · </>}<span className="tabular-nums">{formatDate(ev.latest_note.date, locale, { day: 'numeric', month: 'short' })}</span></figcaption>
        </figure>
      )}

      <MSection title={t('memorization.juz_map')}>
        <div className="rounded-card border border-ink/10 bg-white p-4 shadow-card"><JuzMap cells={p.progress.juz_map} /></div>
      </MSection>

      <TrendChart weeks={ev.trend} />

      <MSection title={t('memorization.latest_scores')}>
        {ev.latest_daily.length === 0 ? <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty icon="evaluation" text={t('memorization.no_scores')} /></div> : (
          <MList label={t('memorization.latest_scores')}>
            {ev.latest_daily.map((e) => (
              <li key={e.id} className="flex min-h-16 items-center gap-3 px-4 py-2.5">
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[15px] font-semibold text-ink"><DualDate value={e.date} /></span>
                  <span className="mt-0.5 block truncate text-[13px] tabular-nums text-ink/65">
                    {(['memorization', 'tajweed', 'revision', 'behavior'] as const).map((k) => `${t(`criteria.${k}`)} ${num(e[k], locale)}`).join(' · ')}
                  </span>
                </span>
                <Pill tone={e.total >= 32 ? 'ok' : e.total >= 24 ? 'warn' : 'err'}>{num(e.total, locale)}/{num(40, locale)}</Pill>
              </li>
            ))}
          </MList>
        )}
      </MSection>

      <MSection title={t('memorization.ledger')}>
        {p.progress.recent.length === 0 ? <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty icon="evaluation" text={t('memorization.no_ledger')} /></div> : (
          <MList label={t('memorization.ledger')}>
            {p.progress.recent.map((r) => (
              <li key={r.id} className="flex min-h-16 items-center gap-3 px-4 py-2.5">
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[15px] font-semibold text-ink"><bdi>{r.surah_name}</bdi> <bdi className="tabular-nums">{num(r.from_ayah, locale)}–{num(r.to_ayah, locale)}</bdi></span>
                  <span className="mt-0.5 block truncate text-[13px] text-ink/65"><DualDate value={r.recorded_on} /></span>
                </span>
                <Pill tone={r.type === 'memorized' ? 'ok' : 'info'}>{r.type_label}</Pill>
              </li>
            ))}
          </MList>
        )}
      </MSection>
    </>
  )
}

export function ChildCertificatesPage() {
  return <ChildPage titleKey="certificates.title">{(card) => <StudentCertificatesTab studentId={card.student.id} />}</ChildPage>
}
