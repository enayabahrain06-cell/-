import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { honorApi, type BadgeRow, type HonorBoard, type HonorRow } from '../../api/engagement'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import { EmptyState, OrnamentDivider, PageBand } from '../../components/ornaments'
import { Badge, buttonClass, Card, CardTitle, ErrorState, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, Segmented, SURFACE, TABLE_HEAD, TableWrap, type Tone } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'

/** Categorical order from the validated palette: attendance, evaluation, memorization, bonus. */
export const BREAKDOWN_COLORS = { attendance: '#2E8B57', evaluation: '#B8872E', memorization: '#3F74C0', bonus: '#B0413A' } as const
const BREAKDOWN_KEYS = ['attendance', 'evaluation', 'memorization', 'bonus'] as const
const STATUS_TONE: Record<HonorBoard['status'], Tone> = { none: 'muted', open: 'gold', finalized: 'brand', honored: 'brand' }
export const MEDAL = ['text-gold-500', 'text-ink/45', 'text-gold-700']

export function monthLabel(period: string, locale: string) {
  return formatDate(`${period}-01`, locale, { month: 'long', year: 'numeric' })
}

function currentMonth() {
  const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Bahrain' }))
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

export default function HonorBoardPage() {
  const { t, i18n } = useTranslation('engagement')
  const locale = i18n.language
  const { can, user } = useAuth()
  const qc = useQueryClient()
  const both = !user?.track || user.track === 'both' || user.roles.includes('super_admin')
  const [period, setPeriod] = useState(currentMonth())
  const [gender, setGender] = useState<'male' | 'female'>(user?.track === 'female' ? 'female' : 'male')
  const [level, setLevel] = useState<'track' | 'package' | 'circle'>('track')
  const [tab, setTab] = useState<'board' | 'badges'>('board')
  const [honorOpen, setHonorOpen] = useState(false)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const manage = can('honor.manage')

  const key = ['honor-board', period, gender, level, locale]
  const q = useQuery({ queryKey: key, queryFn: () => honorApi.board({ period, gender: both ? gender : undefined, level }) })
  const board = q.data?.data
  const fail = (e: unknown) => setNotice({ tone: 'error', text: parseApiError(e).message })
  const compute = useMutation({
    mutationFn: () => honorApi.compute(period, both ? gender : undefined),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); void qc.invalidateQueries({ queryKey: ['honor-board'] }) },
    onError: fail,
  })
  const publish = useMutation({
    mutationFn: (v: boolean) => honorApi.publish(board!.id!, v),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); void qc.invalidateQueries({ queryKey: ['honor-board'] }) },
    onError: fail,
  })

  const displayKey = q.data?.display_key
  const tvUrl = displayKey ? `/display/honor?key=${encodeURIComponent(displayKey)}&gender=${board?.gender ?? gender}&lang=${locale}` : null

  return (
    <div className="space-y-5">
      <PageBand
        title={t('honor.title')}
        subtitle={t('honor.subtitle', { month: monthLabel(period, locale) })}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {tvUrl && <a href={tvUrl} target="_blank" rel="noreferrer" className={buttonClass('onDeep')}><Icon name="tv" className="size-4" /> {t('honor.tv')}</a>}
            {manage && <button type="button" onClick={() => compute.mutate()} disabled={compute.isPending} className={buttonClass('onDeep')}><Icon name="refresh" className="size-4" /> {t('honor.compute')}</button>}
          </div>
        }
      />

      <div className="flex flex-wrap items-end gap-3">
        <label className="text-sm text-ink/70">
          <span className="mb-1 block font-medium">{t('honor.month')}</span>
          <input type="month" value={period} max={currentMonth()} onChange={(e) => e.target.value && setPeriod(e.target.value)} className="rounded-xl border border-ink/15 bg-white px-3 py-2 text-ink" />
        </label>
        {both && <Segmented name="honor-gender" label={t('honor.track')} value={gender} onChange={setGender} options={[{ value: 'male', label: t('display.boys') }, { value: 'female', label: t('display.girls') }]} />}
        <Segmented name="honor-tab" label="" value={tab} onChange={setTab} options={[{ value: 'board', label: t('honor.tab_board') }, { value: 'badges', label: t('honor.tab_badges') }]} />
      </div>

      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {manage && !displayKey && tab === 'board' && <p className="text-xs text-ink/50">{t('honor.tv_no_key')}</p>}

      {tab === 'badges' ? <BadgesPanel manage={manage} /> : q.isLoading ? <LoadingState /> : q.isError || !board ? <ErrorState onRetry={() => void q.refetch()} /> : (
        <>
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <Badge tone={STATUS_TONE[board.status]}>{t(`honor.status.${board.status}`)}</Badge>
            <Badge tone={board.published ? 'brand' : 'muted'}>{board.published ? t('honor.published') : t('honor.not_published')}</Badge>
            <span className="text-ink/60">{t('honor.students_ranked', { n: formatNumber(board.totals.students, locale) })} · {t('honor.badges_awarded', { n: formatNumber(board.totals.badges, locale) })}</span>
            {board.computed_at && <span className="text-xs text-ink/45">{t('honor.computed_at', { when: formatDate(board.computed_at, locale, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) })}</span>}
            {manage && board.id && (
              <span className="ms-auto flex flex-wrap gap-2">
                <SecondaryButton onClick={() => publish.mutate(!board.published)} disabled={publish.isPending}>{board.published ? t('honor.unpublish') : t('honor.publish')}</SecondaryButton>
                <PrimaryButton onClick={() => setHonorOpen(true)} disabled={board.rows.length === 0}><Icon name="medal" className="size-4" /> {board.status === 'honored' ? t('honor.honor_again') : t('honor.honor')}</PrimaryButton>
              </span>
            )}
          </div>

          {board.rows.length === 0 ? (
            <div className={SURFACE}><EmptyState icon="medal" title={t('honor.empty')} body={t('honor.empty_body')} /></div>
          ) : (
            <>
              {level === 'track' && <Podium rows={board.rows.slice(0, 3)} />}
              <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                  <CardTitle actions={<Segmented name="honor-level" size="sm" label="" value={level} onChange={setLevel} options={(['track', 'package', 'circle'] as const).map((v) => ({ value: v, label: t(`honor.level.${v}`) }))} />}>
                    {t('honor.breakdown')}
                  </CardTitle>
                  <WhyLine weights={board.weights} />
                  <RankTable rows={board.rows} level={level} />
                </Card>
                <div className="space-y-4">
                  <CircleOfMonth board={board} />
                </div>
              </div>
            </>
          )}
        </>
      )}

      {honorOpen && board?.id && (
        <HonorDialog board={board} onClose={() => setHonorOpen(false)}
          onDone={(text) => { setHonorOpen(false); setNotice({ tone: 'success', text }); void qc.invalidateQueries({ queryKey: ['honor-board'] }) }} />
      )}
    </div>
  )
}

function WhyLine({ weights }: { weights: HonorBoard['weights'] }) {
  const { t, i18n } = useTranslation('engagement')
  const [open, setOpen] = useState(false)
  const w = { a: formatNumber(weights.attendance, i18n.language), e: formatNumber(weights.evaluation, i18n.language), m: formatNumber(weights.memorization, i18n.language) }
  return (
    <div className="mb-3 text-xs text-ink/60">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
        {BREAKDOWN_KEYS.map((k) => (
          <span key={k} className="inline-flex items-center gap-1.5"><span className="size-2.5 rounded-sm" style={{ background: BREAKDOWN_COLORS[k] }} aria-hidden />{t(`honor.${k}`)}</span>
        ))}
        <button type="button" onClick={() => setOpen(!open)} className="font-medium text-brand-700 underline-offset-2 hover:underline" aria-expanded={open}>{t('honor.why')}</button>
      </div>
      {open && <p className="mt-2 rounded-lg bg-page px-3 py-2 text-ink/70">{t('honor.why_body', w)}</p>}
    </div>
  )
}

function Podium({ rows }: { rows: HonorRow[] }) {
  const { t, i18n } = useTranslation('engagement')
  // Visual order 2 · 1 · 3 on wide screens, 1 · 2 · 3 stacked on phones.
  const order = [1, 0, 2].filter((i) => rows[i])
  return (
    <section aria-label={t('honor.podium')} className="grid gap-3 sm:grid-cols-3 sm:items-end">
      {order.map((i) => {
        const r = rows[i]
        const place = r.rank ?? i + 1
        return (
          <div key={r.student?.id} className={`${SURFACE} relative flex flex-col items-center gap-2 p-4 text-center ${i === 0 ? 'order-first sm:order-none sm:pb-8 sm:pt-6 ring-2 ring-gold-400/50' : ''}`}>
            <Icon name="medal" className={`size-7 ${MEDAL[Math.min(place, 3) - 1]}`} title={t('honor.place', { n: formatNumber(place, i18n.language) })} />
            <Avatar name={r.student?.full_name ?? ''} initial={r.student?.initial} src={r.student?.photo_url} size={i === 0 ? 'lg' : 'md'} />
            <p dir="auto" className="font-semibold text-ink">{r.student?.full_name}</p>
            <p dir="auto" className="text-xs text-ink/55">{r.lesson?.name}</p>
            <p className="font-display text-2xl text-brand-700 tabular-nums">{formatNumber(r.points, i18n.language, { maximumFractionDigits: 1 })}</p>
            <p className="text-xs text-ink/50">{t('honor.place', { n: formatNumber(place, i18n.language) })}</p>
          </div>
        )
      })}
    </section>
  )
}

function Change({ v }: { v: number | null }) {
  const { i18n } = useTranslation()
  if (v === null) return <span className="text-ink/35">—</span>
  const up = v > 0
  return <span className={`tabular-nums ${v === 0 ? 'text-ink/50' : up ? 'text-brand-700' : 'text-danger'}`}>{up ? '▲' : v < 0 ? '▼' : ''} {formatNumber(Math.abs(v), i18n.language, { maximumFractionDigits: 1 })}</span>
}

function BreakdownBar({ row }: { row: HonorRow }) {
  const { t, i18n } = useTranslation('engagement')
  const total = Math.max(1, BREAKDOWN_KEYS.reduce((s, k) => s + row.breakdown[k], 0))
  const title = BREAKDOWN_KEYS.map((k) => `${t(`honor.${k}`)}: ${formatNumber(row.breakdown[k], i18n.language, { maximumFractionDigits: 1 })}`).join(' · ')
  return (
    <div className="flex h-2.5 w-full min-w-24 gap-0.5 overflow-hidden rounded-full bg-ink/5" title={title} role="img" aria-label={title}>
      {BREAKDOWN_KEYS.map((k) => row.breakdown[k] > 0 && <span key={k} style={{ width: `${(row.breakdown[k] / Math.max(total, 100)) * 100}%`, background: BREAKDOWN_COLORS[k] }} className="h-full first:rounded-s-full last:rounded-e-full" />)}
    </div>
  )
}

function RankTable({ rows, level }: { rows: HonorRow[]; level: 'track' | 'package' | 'circle' }) {
  const { t, i18n } = useTranslation('engagement')
  const n = (v: number, d = 0) => formatNumber(v, i18n.language, { maximumFractionDigits: d })
  let lastGroup: string | undefined
  return (
    <TableWrap>
      <table className="w-full text-sm">
        <thead className={TABLE_HEAD}>
          <tr>
            <th className="px-3 py-2 text-start">{t('honor.rank')}</th>
            <th className="px-3 py-2 text-start">{t('honor.student')}</th>
            <th className="hidden px-3 py-2 text-start md:table-cell">{t('honor.attendance')}</th>
            <th className="hidden px-3 py-2 text-start md:table-cell">{t('honor.evaluation')}</th>
            <th className="hidden px-3 py-2 text-start md:table-cell">{t('honor.new_ayahs')}</th>
            <th className="px-3 py-2 text-start">{t('honor.points')}</th>
            <th className="hidden px-3 py-2 text-start sm:table-cell">{t('honor.change')}</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-ink/6">
          {rows.map((r) => {
            const group = level === 'circle' ? r.lesson?.name : level === 'package' ? r.package?.name : undefined
            const header = group && group !== lastGroup
            lastGroup = group
            return [
              header && <tr key={`g-${group}`} className="bg-page/50"><td colSpan={7} dir="auto" className="px-3 py-1.5 text-xs font-semibold text-ink/60">{group}</td></tr>,
              <tr key={`${r.student?.id}`}>
                <td className="px-3 py-2 font-semibold tabular-nums text-ink">{r.rank !== null && r.rank <= 3 ? <Icon name="medal" className={`inline size-4 ${MEDAL[r.rank - 1]}`} /> : null} {r.rank !== null ? n(r.rank) : '—'}</td>
                <td className="px-3 py-2">
                  <div className="flex items-center gap-2">
                    <Avatar name={r.student?.full_name ?? ''} initial={r.student?.initial} src={r.student?.photo_url} size="sm" />
                    <div className="min-w-0"><p dir="auto" className="truncate font-medium text-ink">{r.student?.full_name}</p>{level === 'track' && <p dir="auto" className="truncate text-xs text-ink/50">{r.lesson?.name}</p>}</div>
                  </div>
                </td>
                <td className="hidden px-3 py-2 tabular-nums md:table-cell">{n(r.attendance_pct)}٪</td>
                <td className="hidden px-3 py-2 tabular-nums md:table-cell">{n(r.evaluation_avg, 1)}</td>
                <td className="hidden px-3 py-2 tabular-nums md:table-cell">{n(r.new_ayahs)}</td>
                <td className="px-3 py-2">
                  <div className="flex min-w-28 flex-col gap-1"><span className="font-semibold tabular-nums text-ink">{n(r.points, 1)}</span><BreakdownBar row={r} /></div>
                </td>
                <td className="hidden px-3 py-2 sm:table-cell"><Change v={r.points_change} /></td>
              </tr>,
            ]
          })}
        </tbody>
      </table>
    </TableWrap>
  )
}

function CircleOfMonth({ board }: { board: HonorBoard }) {
  const { t, i18n } = useTranslation('engagement')
  const c = board.circle_of_month
  return (
    <Card>
      <CardTitle>{t('honor.circle_of_month')}</CardTitle>
      {c ? (
        <div className="text-center">
          <Icon name="trophy" className="mx-auto size-10 text-gold-500" />
          <p dir="auto" className="mt-2 font-display text-xl text-ink">{c.name}</p>
          <OrnamentDivider align="center" className="my-2 text-gold-500" />
          <p dir="auto" className="text-sm text-ink/60">{t('honor.circle_of_month_body', { teacher: c.teacher ?? '', points: formatNumber(c.avg_points, i18n.language, { maximumFractionDigits: 1 }), n: formatNumber(c.students, i18n.language) })}</p>
        </div>
      ) : <p className="text-sm text-ink/50">—</p>}
    </Card>
  )
}

function HonorDialog({ board, onClose, onDone }: { board: HonorBoard; onClose: () => void; onDone: (text: string) => void }) {
  const { t, i18n } = useTranslation('engagement')
  const [opts, setOpts] = useState({ certificates: true, messages: board.status !== 'honored', publish: true })
  const [error, setError] = useState<string | null>(null)
  const run = useMutation({
    mutationFn: () => honorApi.honor(board.id!, opts),
    onSuccess: (r) => onDone(t('honor.honor_dialog.done', { c: formatNumber(r.data.certificates, i18n.language), m: formatNumber(r.data.messages, i18n.language) })),
    onError: (e) => setError(parseApiError(e).message),
  })
  return (
    <Modal title={t('honor.honor_dialog.title', { month: monthLabel(board.period, i18n.language) })} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton loading={run.isPending} onClick={() => run.mutate()}>{t('honor.honor_dialog.confirm')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      <p className="text-sm text-ink/70">{t('honor.honor_dialog.body')}</p>
      <ul className="space-y-2">
        {board.rows.filter((r) => (r.rank ?? 99) <= 3).map((r) => (
          <li key={r.student?.id} className="flex items-center gap-2 text-sm"><Icon name="medal" className={`size-4 ${MEDAL[(r.rank ?? 1) - 1]}`} /><span dir="auto" className="text-ink">{r.student?.full_name}</span><span className="ms-auto tabular-nums text-ink/60">{formatNumber(r.points, i18n.language, { maximumFractionDigits: 1 })}</span></li>
        ))}
      </ul>
      <fieldset className="space-y-2">
        {(['certificates', 'messages', 'publish'] as const).map((k) => (
          <label key={k} className="flex items-start gap-2 text-sm text-ink/80"><input type="checkbox" className="mt-0.5 size-4 accent-brand-600" checked={opts[k]} onChange={(e) => setOpts({ ...opts, [k]: e.target.checked })} />{t(`honor.honor_dialog.${k}`)}</label>
        ))}
      </fieldset>
    </Modal>
  )
}

function BadgesPanel({ manage }: { manage: boolean }) {
  const { t, i18n } = useTranslation('engagement')
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['honor-badges', i18n.language], queryFn: honorApi.badges })
  const save = useMutation({
    mutationFn: ({ id, d }: { id: number; d: Partial<BadgeRow> }) => honorApi.updateBadge(id, d),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['honor-badges'] }),
  })
  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState onRetry={() => void q.refetch()} />
  const n = (v: number) => formatNumber(v, i18n.language)
  const ruleText = (b: BadgeRow) => t(`honor.badge.rules.${b.rule_type}`, { v: b.rule_type === 'tajweed_average' && b.rule_value ? n(b.rule_value / 100) : n(b.rule_value ?? 0) })
  return (
    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {q.data.map((b) => (
        <li key={b.id} className={`${SURFACE} flex gap-3 p-4 ${b.is_active ? '' : 'opacity-60'}`}>
          <span className="grid size-11 shrink-0 place-items-center rounded-full bg-gold-500/15 text-gold-700"><Icon name={['medal', 'star', 'calendar', 'flag', 'trophy'].includes(b.icon) ? (b.icon === 'star' ? 'evaluation' : b.icon === 'calendar' ? 'attendance' : b.icon) : 'medal'} /></span>
          <div className="min-w-0 flex-1 space-y-1">
            <p dir="auto" className="font-semibold text-ink">{b.name}</p>
            <p className="text-xs text-ink/60">{ruleText(b)}</p>
            <p className="flex flex-wrap gap-1 text-xs">
              <Badge tone="muted">{b.repeatable_monthly ? t('honor.badge.monthly') : t('honor.badge.once')}</Badge>
              {b.bonus_points > 0 && <Badge tone="gold">+{n(b.bonus_points)}</Badge>}
              <Badge tone="brand">{t('honor.badge.awarded')} {n(b.awarded)}</Badge>
            </p>
          </div>
          {manage && (
            <label className="flex shrink-0 items-start gap-1.5 text-xs text-ink/60">
              <input type="checkbox" className="size-4 accent-brand-600" checked={b.is_active} disabled={save.isPending} onChange={(e) => save.mutate({ id: b.id, d: { is_active: e.target.checked } })} />
              {t('honor.badge.active')}
            </label>
          )}
        </li>
      ))}
    </ul>
  )
}
