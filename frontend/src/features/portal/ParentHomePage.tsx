import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { PortalCard } from '../../api/portal'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { MAvatar, MEmpty, Pill } from '../../components/mobile/atoms'
import { formatDate, formatHijri, formatMoney, formatPercent } from '../../lib/format'
import PortalLayout from './PortalLayout'
import { num, usePortalOverview, useTodayPill } from './hooks'
import { CardSkeleton, MiniKpi, PortalError } from './shared'

/** §6.27 Parent home (/my-children): greeting, due-payment banner, one card per child. */
export default function ParentHomePage() {
  const { t, i18n } = useTranslation('portal')
  const locale = i18n.language
  const { user } = useAuth()
  const q = usePortalOverview()
  const data = q.data
  const today = new Date()

  return (
    <PortalLayout>
      <div className="space-y-6">
        <div>
          <p className="font-display text-2xl font-bold leading-[34px] text-brand-900 lg:text-3xl"><bdi>{t('home.greeting', { name: user?.name?.split(/\s+/)[0] ?? '' })}</bdi></p>
          <p className="mt-0.5 text-[13px] tabular-nums text-ink/65">{formatDate(today, locale, { weekday: 'long', day: 'numeric', month: 'long' })} · {formatHijri(today, locale)}</p>
        </div>

        {data && data.totals.outstanding_fils > 0 && (
          <div className="flex flex-wrap items-center gap-3 rounded-card bg-gold-500/12 p-4">
            <span className="inline-grid size-10 shrink-0 place-items-center rounded-full bg-white/70 text-gold-700"><Icon name="wallet" className="size-5" /></span>
            <div className="min-w-0 flex-[1_1_12rem]">
              <p className="text-[15px] font-semibold text-ink">{t('home.due_title', { amount: formatMoney(data.totals.outstanding_fils, locale) })}</p>
              <p className="text-[13px] text-ink/65">{t('home.due_sub', { count: data.totals.due_students, n: num(data.totals.due_students, locale) })}</p>
            </div>
            <Link to="/my-invoices" className="inline-flex min-h-11 items-center gap-1 px-1 text-[15px] font-semibold text-info">
              {t('home.due_action')}<Icon name="chevron" className="size-4 rtl:rotate-180" />
            </Link>
          </div>
        )}

        {q.isError ? <PortalError onRetry={() => void q.refetch()} /> : q.isLoading ? (
          <div className="grid grid-cols-1 gap-3 lg:grid-cols-2"><CardSkeleton /><CardSkeleton /></div>
        ) : !data || data.students.length === 0 ? (
          <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty text={t('home.empty')} /></div>
        ) : (
          <section aria-label={t('home.children')} className="grid grid-cols-1 items-start gap-3 lg:grid-cols-2">
            {data.students.map((c) => <ChildCard key={c.student.id} card={c} />)}
          </section>
        )}
      </div>
    </PortalLayout>
  )
}

/** Secondary look (M_BTN_SECONDARY) sized for three buttons across a 320px card. */
const CARD_BTN = 'inline-flex min-h-11 min-w-0 items-center justify-center truncate rounded-ctl border border-ink/10 bg-white px-1.5 text-[13px] font-semibold text-brand-700 min-[360px]:text-[15px]'

function ChildCard({ card }: { card: PortalCard }) {
  const { t, i18n } = useTranslation('portal')
  const locale = i18n.language
  const pill = useTodayPill(card)
  const s = card.student
  const base = `/my-children/${s.id}`
  const k = card.kpis
  const juz = card.progress.position.current?.juz ?? card.progress.position.next?.juz

  return (
    <article className="min-w-0 rounded-card border border-ink/10 bg-white p-4 shadow-card" aria-labelledby={`child-${s.id}`}>
      <div className="flex items-start gap-3">
        <MAvatar name={s.full_name} src={s.photo_url} size={52} />
        <div className="min-w-0 flex-1">
          <h2 id={`child-${s.id}`} className="truncate text-[15px] font-semibold text-ink"><bdi>{s.full_name}</bdi></h2>
          <p className="truncate text-[13px] text-ink/65">
            {card.circle ? <><bdi>{card.circle.name}</bdi>{card.circle.teacher && <> · <bdi>{card.circle.teacher}</bdi></>}</> : t('no_circle')}
          </p>
          <Pill tone={pill.tone} className="mt-1.5">{pill.label}</Pill>
        </div>
      </div>

      <div className="mt-4 grid grid-cols-3 gap-2">
        <MiniKpi label={t('kpi.attendance')} value={formatPercent(k.attendance_percent, locale)} />
        <MiniKpi label={t('kpi.juz')} value={juz ? num(juz, locale) : '—'} />
        <MiniKpi label={t('kpi.rating')} value={k.evaluation_average === null ? '—' : `${num(k.evaluation_average, locale, 1)}/${num(k.evaluation_max, locale)}`} />
      </div>

      {card.latest_note && (
        <figure className="mt-3 rounded-ctl bg-page p-3">
          <blockquote className="text-[15px] leading-6 text-ink" dir="auto">{card.latest_note.note}</blockquote>
          <figcaption className="mt-1 truncate text-[13px] text-ink/65">
            {card.latest_note.teacher && <><bdi>{card.latest_note.teacher}</bdi> · </>}
            <span className="tabular-nums">{formatDate(card.latest_note.date, locale, { day: 'numeric', month: 'short' })}</span>
          </figcaption>
        </figure>
      )}

      <div className="mt-4 grid grid-cols-3 gap-2">
        <Link to={`${base}/attendance`} className={CARD_BTN}>{t('actions.attendance')}</Link>
        <Link to={`${base}/memorization`} className={CARD_BTN}>{t('actions.memorization')}</Link>
        {card.wallet.is_due
          ? <Link to={`/my-invoices?student=${s.id}`} className={CARD_BTN}>{t('actions.invoices')}</Link>
          : <Link to={`${base}/certificates`} className={CARD_BTN}>{t('actions.certificates')}</Link>}
      </div>
    </article>
  )
}
