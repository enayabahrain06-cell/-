import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { LotteryDetail, LotteryRow } from '../../api/lottery'
import Icon from '../../components/Icon'
import { Khatam } from '../../components/ornaments'
import BottomSheet from '../../components/mobile/BottomSheet'
import { Fab, StickyActionBar } from '../../components/mobile/ActionBars'
import { HeaderAction, MobilePage } from '../../components/mobile/MobileChrome'
import { MAvatar, MCard, MEmpty, MList, MRow, Pill, Skeleton, M_BTN_PRIMARY, M_BTN_SECONDARY, M_CARD, type PillTone } from '../../components/mobile/atoms'
import { formatDate, formatNumber } from '../../lib/format'

/**
 * The lottery below lg (mobile-redesign-spec.md §6.15). In this app a lottery distributes a package's waiting students
 * over the participating teachers, so the spec's draw card and winner banner become the draw (seed + run) and the
 * distribution result. Queries and mutations stay in LotteryPages.
 */

const STATUS_TONE: Record<string, PillTone> = { draft: 'neutral', run: 'warn', approved: 'ok', cancelled: 'neutral' }
const GENDER_TONE: Record<string, PillTone> = { male: 'ok', female: 'warn', mixed: 'info' }
const OPTIONS = ['keep_siblings', 'balance_ages', 'balance_levels'] as const

export function MobileLotteryList({ rows, loading, error, onRetry, canCreate, onCreate }: {
  rows: LotteryRow[] | undefined; loading: boolean; error: boolean; onRetry: () => void; canCreate: boolean; onCreate: () => void
}) {
  const { t, i18n } = useTranslation('lottery')
  const { t: tl } = useTranslation('lessons')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-3 lg:hidden">
      <p className="text-[13px] text-ink/65">{t('subtitle')}</p>
      {loading ? <ListSkeleton /> : error || !rows ? (
        <MCard><MEmpty icon="alert" text={t('mobile.error')} action={<button type="button" onClick={onRetry} className={M_BTN_SECONDARY}>{t('mobile.retry')}</button>} /></MCard>
      ) : rows.length === 0 ? (
        <MCard><MEmpty icon="lottery" text={t('empty')} /></MCard>
      ) : (
        <ul className="space-y-3">
          {rows.map((l) => (
            <li key={l.id}>
              <Link to={`/lottery/${l.id}`} className={`${M_CARD} block p-4 active:bg-brand-50/60 ${l.status === 'cancelled' ? 'opacity-75' : ''}`}>
                <div className="flex items-start justify-between gap-3">
                  <p className="min-w-0 text-[15px] font-semibold text-ink"><bdi>{l.name}</bdi></p>
                  <Pill tone={STATUS_TONE[l.status]}>{t(`status.${l.status}`)}</Pill>
                </div>
                <p className="mt-1 truncate text-[13px] text-ink/65"><bdi>{l.package?.name ?? '—'}</bdi>{l.run_at && <> · <span className="tabular-nums">{formatDate(l.run_at, locale, { day: 'numeric', month: 'short' })}</span></>}</p>
                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                  <Pill tone="info">{t('counts', { pool: n(l.pool_count), teachers: n(l.teachers_count) })}</Pill>
                  {l.gender && <Pill tone={GENDER_TONE[l.gender] ?? 'neutral'}>{tl(`gender.${l.gender}`)}</Pill>}
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
      {canCreate && <Fab label={t('new')} onClick={onCreate} />}
    </div>
  )
}

function ListSkeleton() {
  return (
    <ul aria-hidden className="space-y-3">
      {[0, 1, 2].map((k) => <li key={k} className={`${M_CARD} space-y-2 p-4`}><Skeleton className="h-4 w-2/3" /><Skeleton className="h-3 w-1/2" /><Skeleton className="h-6 w-28 rounded-full" /></li>)}
    </ul>
  )
}

/** Claims the header while the lottery loads or fails (no layout shift). */
export function MobileLotteryPending({ skeleton = true }: { skeleton?: boolean }) {
  const { t } = useTranslation('lottery')
  return (
    <>
      <MobilePage title={t('title')} back="/lottery" breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('title'), to: '/lottery' }]} />
      {skeleton && <div aria-hidden className="space-y-4 lg:hidden">
        <MCard><Skeleton className="h-5 w-1/2" /><Skeleton className="mt-2 h-4 w-3/4" /><Skeleton className="mt-4 h-12 w-full" /></MCard>
        <Skeleton className="h-40 w-full rounded-card" />
      </div>}
    </>
  )
}

export function MobileLotteryDetail({ l, manage, editable, seed, onSeed, notify, onNotify, running, approving, pooling, onRun, onApprove, onCancel, onEdit, onPool, onMove }: {
  l: LotteryDetail; manage: boolean; editable: boolean
  seed: string; onSeed: (v: string) => void; notify: boolean; onNotify: (v: boolean) => void
  running: boolean; approving: boolean; pooling: boolean
  onRun: () => void; onApprove: () => void; onCancel: () => void; onEdit: () => void; onPool: () => void; onMove: (resultId: number, to: number) => void
}) {
  const { t, i18n } = useTranslation('lottery')
  const { t: tl } = useTranslation('lessons')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const [menu, setMenu] = useState(false)
  const [moving, setMoving] = useState<{ id: number; from: number; name: string } | null>(null)
  const hasResults = l.results_count > 0
  const assigned = l.teachers.reduce((a, x) => a + x.assigned, 0)
  const awaiting = manage && l.status === 'run'

  return (
    <div className="space-y-4 lg:hidden">
      <MobilePage title={l.name} back="/lottery"
        breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('title'), to: '/lottery' }, { label: l.name }]}
        actions={editable ? <HeaderAction icon="more" label={t('mobile.more_actions')} onClick={() => setMenu(true)} /> : undefined} />

      {/* The draw card: package (the pool), the rules as labelled chips, the eligible count, and the seed. */}
      <MCard>
        <div className="flex items-start justify-between gap-3">
          <p className="min-w-0 text-[15px] font-semibold text-ink"><bdi>{l.package?.name ?? '—'}</bdi></p>
          <Pill tone={STATUS_TONE[l.status]}>{t(`status.${l.status}`)}</Pill>
        </div>
        {(OPTIONS.some((k) => l[k]) || l.gender) && (
          <div className="mt-2 flex flex-wrap gap-1.5">
            {l.gender && <Pill tone={GENDER_TONE[l.gender] ?? 'neutral'}>{tl(`gender.${l.gender}`)}</Pill>}
            {OPTIONS.filter((k) => l[k]).map((k) => <Pill key={k} tone="info">{t(`mobile.options.${k}`)}</Pill>)}
          </div>
        )}
        <dl className="mt-3 grid grid-cols-3 gap-2 border-t border-ink/10 pt-3 text-center">
          <Mini label={t('mobile.eligible')} value={n(l.pool_count)} />
          <Mini label={t('mobile.teachers')} value={n(l.teachers_count)} />
          <Mini label={t('mobile.runs')} value={n(l.run_count)} />
        </dl>
        {editable && (
          <div className="mt-3 space-y-2 border-t border-ink/10 pt-3">
            <label className="block">
              <span className="mb-1.5 block text-[13px] font-medium text-ink/75">{t('detail.seed')}</span>
              <input dir="ltr" value={seed} onChange={(e) => onSeed(e.target.value)} className="h-12 w-full rounded-md border border-ink/10 bg-white px-3.5 text-[15px] text-ink" />
            </label>
            <p className="text-xs text-ink/65">{t('detail.seed_hint')}</p>
            {/* After a draw, drawing again is secondary: the screen's primary is the approval. */}
            {l.run_count > 0 && (
              <button type="button" disabled={running} onClick={onRun} className={`${M_BTN_SECONDARY} w-full`}><Icon name="lottery" className="size-5" />{t('detail.rerun')}</button>
            )}
          </div>
        )}
      </MCard>

      {/* Result banner (the spec's winner banner): how many were placed, the seed, and the guardian notice toggle. */}
      {hasResults && (
        <section className="relative overflow-hidden rounded-card bg-deep p-4 text-white shadow-card">
          <Khatam className="pointer-events-none absolute -end-5 -top-5 size-24 text-gold-500 opacity-10" />
          <p className="relative text-xs font-semibold text-gold-300">{l.status === 'approved' ? t('status.approved') : t('mobile.result')}</p>
          <p className="relative mt-1 font-display text-2xl leading-9 text-gold-300 tabular-nums">{t('mobile.placed', { n: n(assigned), total: n(l.pool_count) })}</p>
          <p className="relative mt-0.5 text-[13px] text-white/80">
            {l.seed && <span className="tabular-nums">{t('detail.run_info', { run: n(l.run_count), seed: l.seed })}</span>}
            {l.unassigned.length > 0 && <> · {t('mobile.without_seat', { n: n(l.unassigned.length) })}</>}
          </p>
          {awaiting && (
            <button type="button" aria-pressed={notify} onClick={() => onNotify(!notify)}
              className={`relative mt-3 inline-flex min-h-11 items-center gap-2 rounded-full px-4 text-[13px] font-semibold ${notify ? 'bg-gold-300 text-deep' : 'border border-white/30 text-white'}`}>
              <Icon name={notify ? 'check' : 'bell'} className="size-4" />{t('mobile.notify')}
            </button>
          )}
        </section>
      )}

      {!hasResults ? (
        <section className="space-y-3">
          <div className="flex items-center justify-between gap-3">
            <h2 className="text-lg font-semibold text-ink">{t('detail.pool')} <span className="text-[13px] font-normal tabular-nums text-ink/65">({n(l.pool.length)})</span></h2>
            {editable && <button type="button" disabled={pooling} onClick={onPool} className="-me-2 inline-flex min-h-11 items-center gap-1.5 px-2 text-[13px] font-semibold text-info"><Icon name="refresh" className="size-4" />{t('detail.refresh_pool')}</button>}
          </div>
          {l.pool.length === 0 ? <MCard><MEmpty icon="students" text={t('detail.no_results')} /></MCard> : (
            <MList label={t('detail.pool')}>
              {l.pool.map((s) => <MRow key={s.id} leading={<MAvatar name={s.full_name} src={s.photo_url} />} title={s.full_name} caption={s.memorization_level ?? undefined} />)}
            </MList>
          )}
        </section>
      ) : (
        <>
          {l.teachers.map((tc) => (
            <section key={tc.lottery_teacher_id} className="space-y-2">
              <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                  <h2 className="truncate text-[15px] font-semibold text-ink"><bdi>{tc.teacher.name}</bdi></h2>
                  <p className="truncate text-[13px] text-ink/65"><Link to={`/lessons/${tc.lesson.id}`} className="text-info"><bdi>{tc.lesson.name}</bdi></Link></p>
                </div>
                <Pill tone={tc.assigned >= tc.capacity ? 'warn' : 'neutral'}>{t('detail.of', { n: n(tc.assigned), c: n(tc.capacity) })}</Pill>
              </div>
              {tc.students.length === 0 ? <MCard><MEmpty icon="students" text={t('mobile.no_students')} /></MCard> : (
                <MList label={tc.teacher.name}>
                  {tc.students.map((s) => (
                    <MRow key={s.result_id} leading={<MAvatar name={s.student.full_name} src={s.student.photo_url} />} title={s.student.full_name}
                      caption={[s.age_at_start !== null ? t('detail.age', { n: n(Math.floor(s.age_at_start)) }) : null, s.student.memorization_level].filter(Boolean).join(' · ') || undefined}
                      trailing={editable && l.teachers.length > 1 ? (
                        <button type="button" onClick={() => setMoving({ id: s.result_id, from: tc.lottery_teacher_id, name: s.student.full_name })}
                          aria-label={`${t('detail.move_to')}: ${s.student.full_name}`} title={t('detail.move_to')}
                          className="inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700">
                          <Icon name="lottery" className="size-5" />
                        </button>
                      ) : undefined} />
                  ))}
                </MList>
              )}
            </section>
          ))}
          {l.unassigned.length > 0 && (
            <section className="rounded-card border border-danger/25 bg-danger/10 p-4">
              <h2 className="text-[15px] font-semibold text-danger">{t('detail.unassigned')} <span className="tabular-nums">({n(l.unassigned.length)})</span></h2>
              <ul className="mt-2 flex flex-wrap gap-1.5">{l.unassigned.map((s) => <li key={s.id}><Pill tone="err"><bdi>{s.full_name}</bdi></Pill></li>)}</ul>
            </section>
          )}
        </>
      )}

      {editable && l.run_count === 0 && (
        <StickyActionBar>
          <button type="button" disabled={running || l.pool_count === 0} onClick={onRun} className={`${M_BTN_PRIMARY} flex-1`}><Icon name="lottery" className="size-5" />{t('mobile.draw')}</button>
        </StickyActionBar>
      )}
      {awaiting && (
        <StickyActionBar>
          <button type="button" disabled={approving} onClick={onApprove} className={`${M_BTN_PRIMARY} min-w-0 flex-1`}><Icon name="check" className="size-5 shrink-0" /><span className="truncate">{t('detail.approve')}</span></button>
        </StickyActionBar>
      )}

      <BottomSheet open={!!moving} onClose={() => setMoving(null)} title={moving ? `${t('detail.move_to')}: ${moving.name}` : ''}>
        {moving && (
          <MList>
            {l.teachers.filter((x) => x.lottery_teacher_id !== moving.from).map((x) => (
              <li key={x.lottery_teacher_id}>
                <button type="button" onClick={() => { onMove(moving.id, x.lottery_teacher_id); setMoving(null) }} className="flex min-h-16 w-full items-center gap-3 px-4 py-2.5 text-start">
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[15px] font-semibold text-ink"><bdi>{x.teacher.name}</bdi></span>
                    <span className="block truncate text-[13px] text-ink/65"><bdi>{x.lesson.name}</bdi></span>
                  </span>
                  <Pill tone={x.assigned >= x.capacity ? 'warn' : 'neutral'}>{t('detail.of', { n: n(x.assigned), c: n(x.capacity) })}</Pill>
                </button>
              </li>
            ))}
          </MList>
        )}
      </BottomSheet>

      <BottomSheet open={menu} onClose={() => setMenu(false)} title={t('mobile.more_actions')}>
        <MList>
          <li><button type="button" onClick={() => { setMenu(false); onEdit() }} className="flex min-h-[52px] w-full items-center gap-3 px-4 text-[15px] text-ink"><Icon name="settings" className="size-5 text-brand-700" />{t('detail.edit')}</button></li>
          <li><button type="button" onClick={() => { setMenu(false); onCancel() }} className="flex min-h-[52px] w-full items-center gap-3 px-4 text-[15px] text-danger"><Icon name="ban" className="size-5" />{t('detail.cancel')}</button></li>
        </MList>
      </BottomSheet>
    </div>
  )
}

function Mini({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="truncate text-xs text-ink/65">{label}</dt>
      <dd className="mt-0.5 truncate text-lg font-semibold tabular-nums text-ink">{value}</dd>
    </div>
  )
}
