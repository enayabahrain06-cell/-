import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import type { HonorBoard, HonorRow } from '../../api/engagement'
import type { HonorTab } from './HonorBoardPage'
import Icon from '../../components/Icon'
import { Khatam } from '../../components/ornaments'
import { StickyActionBar } from '../../components/mobile/ActionBars'
import { MobilePage } from '../../components/mobile/MobileChrome'
import { MAvatar, MCard, MEmpty, MList, MSegmented, Pill, Skeleton, M_BTN_PRIMARY, M_BTN_SECONDARY, type PillTone } from '../../components/mobile/atoms'
import { formatDate, formatNumber } from '../../lib/format'

/** Excellence board below lg (mobile-redesign-spec.md §6.16). State, query and mutations stay in HonorBoardPage. */

type Level = 'track' | 'package' | 'circle'
const STATUS_TONE: Record<HonorBoard['status'], PillTone> = { none: 'neutral', open: 'warn', finalized: 'ok', honored: 'ok' }
const MEDAL = ['text-gold-500', 'text-ink/65', 'text-gold-700']

export default function MobileHonor({ board, loading, error, onRetry, period, maxPeriod, onPeriod, both, gender, onGender, tab, onTab, tabOptions, level, onLevel, manage, displayKey, tvUrl, computing, publishing, onCompute, onPublish, onHonor, badges, grades }: {
  board: HonorBoard | undefined; loading: boolean; error: boolean; onRetry: () => void
  period: string; maxPeriod: string; onPeriod: (v: string) => void
  both: boolean; gender: 'male' | 'female'; onGender: (v: 'male' | 'female') => void
  tab: HonorTab; onTab: (v: HonorTab) => void; tabOptions: { value: HonorTab; label: string }[]; level: Level; onLevel: (v: Level) => void
  manage: boolean; displayKey: string | null | undefined; tvUrl: string | null
  computing: boolean; publishing: boolean; onCompute: () => void; onPublish: (v: boolean) => void; onHonor: () => void
  badges: ReactNode; grades: ReactNode
}) {
  const { t, i18n } = useTranslation('engagement')
  const locale = i18n.language
  const pts = (v: number) => formatNumber(v, locale, { maximumFractionDigits: 1 })
  const w = board && { a: formatNumber(board.weights.attendance, locale), e: formatNumber(board.weights.evaluation, locale), m: formatNumber(board.weights.memorization, locale) }
  const group = (r?: HonorRow) => (level === 'circle' ? r?.lesson?.name : level === 'package' ? r?.package?.name : undefined)

  return (
    <div className="space-y-4 lg:hidden">
      <MobilePage title={t('honor.title')} back="/" breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('honor.title') }]}
        actions={tvUrl ? (
          <a href={tvUrl} target="_blank" rel="noreferrer" aria-label={t('honor.tv')} title={t('honor.tv')} className="inline-grid size-11 place-items-center rounded-ctl text-brand-900">
            <Icon name="tv" className="size-[22px]" />
          </a>
        ) : undefined} />

      <div className={`grid gap-3 ${both ? 'min-[400px]:grid-cols-[minmax(0,1fr)_auto] min-[400px]:items-end' : ''}`}>
        {tab !== 'grades' && <label className="block min-w-0">
          <span className="mb-1.5 block text-[13px] font-medium text-ink/75">{t('honor.month')}</span>
          <input type="month" value={period} max={maxPeriod} onChange={(e) => e.target.value && onPeriod(e.target.value)}
            className="h-12 w-full min-w-0 rounded-md border border-ink/10 bg-white px-3 text-[15px] tabular-nums text-ink" />
        </label>}
        {both && (
          <div role="group" aria-label={t('honor.track')} className="flex h-12 gap-[3px] rounded-ctl bg-ink/5 p-[3px]">
            {(['male', 'female'] as const).map((g) => (
              <button key={g} type="button" aria-pressed={gender === g} onClick={() => onGender(g)}
                className={`flex-1 rounded-lg px-3 text-[13px] font-semibold ${gender === g ? 'bg-white text-brand-700 shadow-card' : 'text-ink/65'}`}>
                {t(g === 'male' ? 'display.boys' : 'display.girls')}
              </button>
            ))}
          </div>
        )}
      </div>
      <MSegmented label={t('honor.view')} value={tab} onChange={onTab} options={tabOptions} />

      {tab === 'grades' ? grades : tab === 'badges' ? badges : loading ? <BoardSkeleton /> : error || !board ? (
        <MCard><MEmpty icon="alert" text={t('mobile.error')} action={<button type="button" onClick={onRetry} className={M_BTN_SECONDARY}>{t('mobile.retry')}</button>} /></MCard>
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-1.5">
            <Pill tone={STATUS_TONE[board.status]}>{t(`honor.status.${board.status}`)}</Pill>
            <Pill tone={board.published ? 'ok' : 'neutral'}>{board.published ? t('honor.published') : t('honor.not_published')}</Pill>
            <span className="text-[13px] tabular-nums text-ink/65">{t('honor.students_ranked', { n: formatNumber(board.totals.students, locale) })} · {t('honor.badges_awarded', { n: formatNumber(board.totals.badges, locale) })}</span>
          </div>
          {board.computed_at && <p className="-mt-2 text-xs text-ink/65">{t('honor.computed_at', { when: formatDate(board.computed_at, locale, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) })}</p>}
          {manage && (
            <div className="grid grid-cols-2 gap-2">
              <button type="button" disabled={computing} onClick={onCompute} className={`${M_BTN_SECONDARY} px-3 text-[13px]`}><Icon name="refresh" className="size-4" />{t('honor.compute')}</button>
              {board.id
                ? <button type="button" disabled={publishing} onClick={() => onPublish(!board.published)} className={`${M_BTN_SECONDARY} px-3 text-[13px]`}><Icon name="eye" className="size-4" />{board.published ? t('honor.unpublish') : t('honor.publish')}</button>
                : <span />}
            </div>
          )}
          {manage && !displayKey && <p className="text-xs text-ink/65">{t('honor.tv_no_key')}</p>}

          {board.rows.length === 0 ? (
            <MCard><MEmpty icon="medal" text={t('honor.empty')} /></MCard>
          ) : (
            <>
              {level === 'track' && <Podium rows={board.rows.slice(0, 3)} />}
              {w && <p className="text-[13px] leading-5 text-ink/65">{t('honor.why_body', w)}</p>}
              <MSegmented label={t('honor.breakdown')} value={level} onChange={onLevel} options={(['track', 'package', 'circle'] as const).map((v) => ({ value: v, label: t(`mobile.level.${v}`) }))} />
              <MList label={t('honor.breakdown')}>
                {board.rows.map((r, i) => {
                  const g = group(r)
                  const header = g && g !== group(board.rows[i - 1])
                  return [
                    header && <li key={`g-${g}`} className="bg-page px-4 py-2 text-xs font-semibold text-ink/65"><bdi>{g}</bdi></li>,
                    <li key={r.student?.id ?? i} className="flex min-h-16 items-center gap-3 px-4 py-2.5">
                      <span className="flex w-7 shrink-0 flex-col items-center">
                        {r.rank !== null && r.rank <= 3 && <Icon name="medal" className={`size-4 ${MEDAL[r.rank - 1]}`} />}
                        <span className="text-[15px] font-semibold tabular-nums text-ink">{r.rank !== null ? formatNumber(r.rank, locale) : '—'}</span>
                      </span>
                      <MAvatar name={r.student?.full_name ?? '?'} src={r.student?.photo_url} />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-[15px] font-semibold text-ink"><bdi>{r.student?.full_name}</bdi></span>
                        {level === 'track' && r.lesson && <span className="mt-0.5 block truncate text-[13px] text-ink/65"><bdi>{r.lesson.name}</bdi></span>}
                      </span>
                      <span className="shrink-0 text-end">
                        <span className="block text-[15px] font-semibold tabular-nums text-gold-700">{pts(r.points)}</span>
                        {r.points_change !== null && r.points_change !== 0 && (
                          <span className={`block text-xs tabular-nums ${r.points_change > 0 ? 'text-brand-700' : 'text-danger'}`}>
                            <span aria-hidden>{r.points_change > 0 ? '▲' : '▼'}</span> <span className="sr-only">{t('honor.change')}: </span>{pts(Math.abs(r.points_change))}
                          </span>
                        )}
                      </span>
                    </li>,
                  ]
                })}
              </MList>
              {board.circle_of_month && (
                <MCard className="flex items-center gap-3">
                  <span className="inline-grid size-10 shrink-0 place-items-center rounded-full bg-gold-500/12 text-gold-700"><Icon name="trophy" className="size-5" /></span>
                  <div className="min-w-0">
                    <p className="text-xs font-semibold text-ink/65">{t('honor.circle_of_month')}</p>
                    <p className="truncate text-[15px] font-semibold text-ink"><bdi>{board.circle_of_month.name}</bdi></p>
                    <p className="text-[13px] text-ink/65">{t('honor.circle_of_month_body', { teacher: board.circle_of_month.teacher ?? '', points: pts(board.circle_of_month.avg_points), n: formatNumber(board.circle_of_month.students, locale) })}</p>
                  </div>
                </MCard>
              )}
            </>
          )}

          {manage && board.id && (
            <StickyActionBar>
              <button type="button" disabled={board.rows.length === 0} onClick={onHonor} className={`${M_BTN_PRIMARY} flex-1`}>
                <Icon name="medal" className="size-5" />{board.status === 'honored' ? t('honor.honor_again') : t('honor.honor')}
              </button>
            </StickyActionBar>
          )}
        </>
      )}
    </div>
  )
}

/** Three cards, first in the middle (gold border, 56px avatar, taller), rank badge and points in gold-700. */
function Podium({ rows }: { rows: HonorRow[] }) {
  const { t, i18n } = useTranslation('engagement')
  const order = [1, 0, 2].filter((i) => rows[i])
  return (
    <section aria-label={t('honor.podium')} className="relative grid grid-cols-3 items-end gap-2">
      {order.map((i) => {
        const r = rows[i]
        const place = r.rank ?? i + 1
        const first = i === 0
        return (
          <div key={r.student?.id ?? i} className={`relative flex min-w-0 flex-col items-center gap-1.5 overflow-hidden rounded-card bg-white px-2 text-center shadow-card ${first ? 'border-2 border-gold-500 pb-4 pt-5' : 'border border-ink/10 pb-3 pt-3'}`}>
            {first && <Khatam className="pointer-events-none absolute -end-4 -top-4 size-14 text-gold-500 opacity-10" />}
            <span className="relative">
              <MAvatar name={r.student?.full_name ?? '?'} src={r.student?.photo_url} size={first ? 56 : 44} />
              <span className={`absolute -bottom-1 -end-1 inline-grid size-6 place-items-center rounded-full text-xs font-semibold tabular-nums ring-2 ring-white ${first ? 'bg-gold-500 text-white' : 'bg-ink/10 text-ink'}`}
                title={t('honor.place', { n: formatNumber(place, i18n.language) })}>
                {formatNumber(place, i18n.language)}
              </span>
            </span>
            <p className="line-clamp-2 w-full text-[13px] font-semibold leading-5 text-ink"><bdi>{r.student?.full_name}</bdi></p>
            <p className="w-full truncate text-xs text-ink/65"><bdi>{r.lesson?.name}</bdi></p>
            <p className={`font-semibold tabular-nums text-gold-700 ${first ? 'text-xl' : 'text-lg'}`}>{formatNumber(r.points, i18n.language, { maximumFractionDigits: 1 })}</p>
            <span className="sr-only">{t('honor.points')}</span>
          </div>
        )
      })}
    </section>
  )
}

function BoardSkeleton() {
  return (
    <div aria-hidden className="space-y-4">
      <div className="grid grid-cols-3 items-end gap-2"><Skeleton className="h-36 rounded-card" /><Skeleton className="h-44 rounded-card" /><Skeleton className="h-36 rounded-card" /></div>
      <Skeleton className="h-10 w-full" />
      <Skeleton className="h-64 w-full rounded-card" />
    </div>
  )
}
