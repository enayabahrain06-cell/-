import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { competitionsApi, type CompetitionRow } from '../../api/engagement'
import { Fab } from '../../components/mobile/ActionBars'
import { MobilePage } from '../../components/mobile/MobileChrome'
import { MCard, MEmpty, MSegmented, Pill, Skeleton, M_BTN_SECONDARY, M_CARD, type PillTone } from '../../components/mobile/atoms'
import { formatDate, formatNumber } from '../../lib/format'

/** Competitions below lg (mobile-redesign-spec.md §6.17). The list query is the desktop one (same key). */

const TONE: Record<CompetitionRow['status'], PillTone> = { draft: 'neutral', open: 'ok', running: 'warn', judging: 'warn', finished: 'info', cancelled: 'neutral' }
/** The spec's three tabs group the existing statuses. */
const GROUPS = { running: ['running', 'judging'], upcoming: ['draft', 'open'], finished: ['finished', 'cancelled'] } as const
type Group = keyof typeof GROUPS

export function MobileCompetitionsTabs({ tab, onTab, show }: { tab: 'competitions' | 'challenges'; onTab: (v: 'competitions' | 'challenges') => void; show: boolean }) {
  const { t } = useTranslation('engagement')
  if (!show) return null
  return (
    <div className="lg:hidden">
      <MSegmented label={t('competitions.title')} value={tab} onChange={onTab}
        options={[{ value: 'competitions', label: t('competitions.tab_competitions') }, { value: 'challenges', label: t('competitions.tab_challenges') }]} />
    </div>
  )
}

export function MobileCompetitionList({ canCreate, onCreate }: { canCreate: boolean; onCreate: () => void }) {
  const { t, i18n } = useTranslation('engagement')
  const locale = i18n.language
  const n = (v: number | null) => formatNumber(v ?? 0, locale)
  const d = (v: string) => formatDate(v, locale, { day: 'numeric', month: 'short' })
  const q = useQuery({ queryKey: ['competitions', locale], queryFn: () => competitionsApi.list({ per_page: 50 }) })
  const rows = q.data?.data
  const count = (g: Group) => rows?.filter((c) => (GROUPS[g] as readonly string[]).includes(c.status)).length
  // Start on the first group that has something (running, then upcoming, then finished).
  const [picked, setPicked] = useState<Group | null>(null)
  const group: Group = picked ?? ((['running', 'upcoming', 'finished'] as const).find((g) => (count(g) ?? 0) > 0) ?? 'running')
  const list = rows?.filter((c) => (GROUPS[group] as readonly string[]).includes(c.status))

  return (
    <div className="space-y-3 lg:hidden">
      <MSegmented label={t('competitions.tab_competitions')} value={group} onChange={setPicked}
        options={(Object.keys(GROUPS) as Group[]).map((g) => ({ value: g, label: count(g) ? `${t(`mobile.groups.${g}`)} ${n(count(g)!)}` : t(`mobile.groups.${g}`) }))} />

      {q.isLoading ? <ListSkeleton /> : q.isError || !list ? (
        <MCard><MEmpty icon="alert" text={t('mobile.error')} action={<button type="button" onClick={() => void q.refetch()} className={M_BTN_SECONDARY}>{t('mobile.retry')}</button>} /></MCard>
      ) : list.length === 0 ? (
        <MCard><MEmpty icon="trophy" text={rows && rows.length > 0 ? t('mobile.empty_group') : t('competitions.empty')} /></MCard>
      ) : (
        <ul className="space-y-3">
          {list.map((c) => {
            const pct = c.max_participants ? Math.min(100, Math.round(((c.participants_count ?? 0) / c.max_participants) * 100)) : null
            return (
              <li key={c.id} className={`${M_CARD} p-4 ${c.status === 'cancelled' ? 'opacity-75' : ''}`}>
                <div className="flex items-start justify-between gap-3">
                  <Link to={`/competitions/${c.id}`} className="min-w-0 text-[15px] font-semibold text-ink"><bdi>{c.name}</bdi></Link>
                  <Pill tone={TONE[c.status]}>{t(`competitions.status.${c.status}`)}</Pill>
                </div>
                <p className="mt-1 text-[13px] text-ink/65">
                  {t(`competitions.type.${c.type}`)} · {t(`competitions.scope.${c.scope}`)} · {c.gender === 'female' ? t('display.girls') : t('display.boys')} · <span className="tabular-nums">{c.min_age || c.max_age ? t('competitions.ages', { min: n(c.min_age ?? 3), max: n(c.max_age ?? 99) }) : t('competitions.any_age')}</span>
                </p>
                <p className="mt-0.5 text-[13px] tabular-nums text-ink/65">
                  {c.status === 'draft' || c.status === 'open' ? t('competitions.registration', { from: d(c.registration_opens_at), to: d(c.registration_closes_at) }) : `${d(c.starts_at)} – ${d(c.ends_at)}`}
                </p>
                <div className="mt-3 flex items-center gap-3">
                  {pct !== null && (
                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-ink/5" aria-hidden>
                      <div className={`h-full rounded-full ${pct >= 100 ? 'bg-chart-late' : 'bg-chart-present'}`} style={{ width: `${pct}%` }} />
                    </div>
                  )}
                  <span className={`shrink-0 text-xs tabular-nums text-ink/65 ${pct === null ? 'flex-1' : ''}`}>
                    {c.max_participants ? t('mobile.participants_of', { n: n(c.participants_count), max: n(c.max_participants) }) : t('mobile.participants', { n: n(c.participants_count) })}
                    {' · '}{t('mobile.rounds', { n: n(c.rounds_count) })}
                  </span>
                </div>
                {/* Registration open: a direct way in to register students (the competition's participants tab). */}
                {c.registration_open && (
                  <Link to={`/competitions/${c.id}`} className={`${M_BTN_SECONDARY} mt-3 w-full px-3 text-[13px]`}>{t('mobile.register')}</Link>
                )}
              </li>
            )
          })}
        </ul>
      )}
      {canCreate && <Fab label={t('competitions.new')} onClick={onCreate} />}
    </div>
  )
}

/** The challenges tab keeps the existing panel; this adds its create FAB below lg. */
export function MobileChallengesFab({ canCreate, onCreate }: { canCreate: boolean; onCreate: () => void }) {
  const { t } = useTranslation('engagement')
  return canCreate ? <div className="lg:hidden"><Fab label={t('challenges.new')} onClick={onCreate} /></div> : null
}

/** Detail page header below lg: name, back to the list, breadcrumb (claimed while loading, no layout shift). */
export function MobileCompetitionHeader({ name }: { name?: string }) {
  const { t } = useTranslation('engagement')
  const crumbs = [{ label: t('mobile:home'), to: '/' }, { label: t('competitions.title'), to: '/competitions' }]
  return <MobilePage title={name ?? t('competitions.title')} back="/competitions" breadcrumb={name ? [...crumbs, { label: name }] : crumbs} />
}

function ListSkeleton() {
  return (
    <ul aria-hidden className="space-y-3">
      {[0, 1, 2].map((k) => (
        <li key={k} className={`${M_CARD} space-y-2 p-4`}>
          <Skeleton className="h-4 w-2/3" /><Skeleton className="h-3 w-3/4" /><Skeleton className="h-1.5 w-full" /><Skeleton className="h-11 w-full" />
        </li>
      ))}
    </ul>
  )
}
