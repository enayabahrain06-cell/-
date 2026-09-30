import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { challengesApi } from '../../api/engagement'
import { portalApi, type PortalCard } from '../../api/portal'
import Icon from '../../components/Icon'
import { M_BTN_PRIMARY, M_BTN_SECONDARY, MEmpty, MSection, Pill, Skeleton } from '../../components/mobile/atoms'
import { Khatam } from '../../components/ornaments'
import { formatDate, formatPercent } from '../../lib/format'
import PortalLayout from './PortalLayout'
import { firstName, num, useFamilyStudent } from './hooks'
import { CardSkeleton, PortalError } from './shared'

/** §6.28 Student progress (/my-progress): hero with the juz ring, this week, achievements. */
export default function StudentProgressPage() {
  const { t } = useTranslation('portal')
  const { card, loading, error, refetch } = useFamilyStudent()

  return (
    <PortalLayout>
      {error ? <PortalError onRetry={refetch} /> : loading ? (
        <div className="space-y-4"><Skeleton className="h-44 w-full rounded-card" /><CardSkeleton /></div>
      ) : !card ? (
        <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty text={t('progress.empty')} /></div>
      ) : <Progress card={card} />}
    </PortalLayout>
  )
}

function Progress({ card }: { card: PortalCard }) {
  const { t, i18n } = useTranslation('portal')
  const locale = i18n.language
  const s = card.student
  const juz = card.progress.current_juz
  const next = card.progress.position.next
  const pct = juz?.percent ?? 0
  const w = card.week.attendance
  const held = w.present + w.late + w.absent + w.excused
  const challenges = useQuery({ queryKey: ['my-challenges', undefined, locale], queryFn: () => challengesApi.mine(), staleTime: 60_000 })
  const badges = useQuery({ queryKey: ['portal-badges', s.id, locale], queryFn: () => portalApi.badges(s.id), staleTime: 60_000 })
  const active = challenges.data?.mine.find((c) => c.participant_status === 'joined') ?? challenges.data?.mine[0]
  const list = badges.data?.[0]?.badges ?? []

  return (
    <div className="space-y-6">
      {/* Hero */}
      <section className="relative overflow-hidden rounded-card bg-deep p-5 text-white lg:p-8" aria-labelledby="hero-title">
        <Khatam className="pointer-events-none absolute -end-6 -top-6 size-28 text-gold-400 opacity-10" />
        <div className="relative flex items-center gap-4 lg:gap-6">
          <div role="img" aria-label={t('progress.ring', { juz: num(juz?.juz ?? 30, locale), percent: formatPercent(pct, locale) })}
            className="grid size-[84px] shrink-0 place-items-center rounded-full lg:size-28"
            style={{ background: `conic-gradient(var(--color-gold-400) ${pct * 3.6}deg, rgb(255 255 255 / 0.14) 0deg)` }}>
            <span className="grid size-[68px] place-items-center rounded-full bg-deep text-center lg:size-[92px]">
              <span className="text-lg font-semibold tabular-nums text-gold-300 lg:text-2xl">{formatPercent(pct, locale)}</span>
            </span>
          </div>
          <div className="min-w-0 flex-1">
            <h1 id="hero-title" className="truncate font-display text-2xl font-bold leading-[34px] text-gold-300 lg:text-3xl"><bdi>{t('progress.well_done', { name: firstName(s.full_name) })}</bdi></h1>
            {juz && <p className="mt-1 text-[15px] tabular-nums text-white/85">{t('progress.juz_line', { juz: num(juz.juz, locale), memorized: num(juz.memorized, locale), total: num(juz.total, locale) })}</p>}
            {next && <p className="mt-0.5 truncate text-[13px] text-white/75">{t('progress.next_surah', { surah: next.surah_name, ayah: num(next.ayah, locale) })}</p>}
          </div>
        </div>
        <div className="relative mt-4 grid grid-cols-3 gap-2 border-t border-white/10 pt-4 text-center">
          <HeroStat label={t('kpi.memorized_ayahs')} value={num(card.kpis.memorized_ayahs, locale)} />
          <HeroStat label={t('kpi.quran')} value={formatPercent(card.kpis.quran_percent, locale)} />
          <HeroStat label={t('kpi.plan')} value={formatPercent(card.progress.plan.percent, locale)} />
        </div>
      </section>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* This week */}
        <MSection title={t('progress.this_week')}>
          <div className="space-y-4 rounded-card border border-ink/10 bg-white p-4 shadow-card">
            <div className="flex items-center justify-between gap-3">
              <span className="text-[15px] text-ink">{t('progress.attendance_week')}</span>
              {held === 0 ? <Pill>{t('progress.no_sessions')}</Pill> : (
                <Pill tone={w.absent > 0 ? 'warn' : 'ok'}>{t('progress.attended_of', { n: num(w.present + w.late, locale), of: num(held - w.excused, locale) })}</Pill>
              )}
            </div>
            <div>
              <p className="mb-2 text-xs font-semibold text-ink/65">{t('progress.recitations')}</p>
              {card.week.recitations.length === 0 ? <p className="text-[13px] text-ink/65">{t('progress.no_recitations')}</p> : (
                <ul className="divide-y divide-ink/10">
                  {card.week.recitations.map((r) => (
                    <li key={r.id} className="flex items-center gap-3 py-2">
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-[15px] text-ink"><bdi>{r.surah_name}</bdi> <bdi className="tabular-nums">{num(r.from_ayah, locale)}–{num(r.to_ayah, locale)}</bdi></span>
                        <span className="block text-[13px] tabular-nums text-ink/65">{r.type_label} · {formatDate(r.recorded_on, locale, { weekday: 'short', day: 'numeric', month: 'short' })}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
            {active && (
              <div>
                <div className="mb-1.5 flex items-center justify-between gap-3">
                  <p className="min-w-0 truncate text-[13px] font-semibold text-ink"><bdi>{active.name}</bdi></p>
                  <span className="shrink-0 text-[13px] tabular-nums text-ink/65">{formatPercent(active.progress_pct, locale)}</span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-ink/5" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(active.progress_pct)} aria-label={active.name}>
                  <div className="h-full rounded-full bg-gold-500" style={{ width: `${Math.min(100, active.progress_pct)}%` }} />
                </div>
              </div>
            )}
          </div>
        </MSection>

        {/* Achievements */}
        <MSection title={t('progress.achievements')} action={{ label: t('progress.honor_link'), to: '/my/honor' }}>
          <div className="rounded-card border border-ink/10 bg-white p-4 shadow-card">
            {badges.isLoading ? (
              <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-24 rounded-ctl" />)}</div>
            ) : list.length === 0 ? <MEmpty icon="medal" text={t('progress.no_badges')} /> : (
              <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                {list.map((b) => (
                  <li key={b.id} title={b.description ?? undefined}
                    className={`flex min-h-24 flex-col items-center justify-center gap-1.5 rounded-ctl p-2 text-center ${b.earned ? 'bg-gold-500/12 text-gold-700' : 'bg-ink/5 text-ink/65'}`}>
                    <Icon name={b.earned ? 'medal' : 'ban'} className={`size-6 ${b.earned ? '' : 'opacity-60'}`} />
                    <span className="line-clamp-2 text-xs font-semibold leading-4"><bdi>{b.name}</bdi></span>
                    <span className="sr-only">{b.earned ? t('progress.earned') : t('progress.locked')}</span>
                    {b.times > 1 && <span className="text-xs tabular-nums">{t('progress.times', { n: num(b.times, locale) })}</span>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </MSection>
      </div>

      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <Link to="/my-progress/memorization" className={`${M_BTN_PRIMARY} w-full`}>{t('actions.memorization_details')}</Link>
        <Link to="/my-progress/attendance" className={`${M_BTN_SECONDARY} w-full`}>{t('actions.attendance')}</Link>
        <Link to="/my-progress/certificates" className={`${M_BTN_SECONDARY} w-full`}>{t('actions.certificates')}</Link>
        <Link to="/my/exams" className={`${M_BTN_SECONDARY} w-full`}>{t('links.exams')}</Link>
      </div>
    </div>
  )
}

function HeroStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <p className="truncate text-lg font-semibold tabular-nums text-white">{value}</p>
      <p className="truncate text-xs text-white/75">{label}</p>
    </div>
  )
}
