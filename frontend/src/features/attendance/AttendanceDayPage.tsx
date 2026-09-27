import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { attendanceApi } from '../../api/attendance'
import Icon from '../../components/Icon'
import { PageBand } from '../../components/ornaments'
import { Badge, ErrorState, LoadingState, SecondaryButton, SURFACE, inputClass, EmptyCard } from '../../components/ui'
import { formatDate, formatHijri, formatNumber, formatTime } from '../../lib/format'

const todayIso = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bahrain' }).format(new Date())

/** Pick a day, see its circles, open a sheet. Teachers only see their own circles (server-side). */
export default function AttendanceDayPage({ basePath = '/attendance', title, subtitle, embedded = false }: { basePath?: string; title?: string; subtitle?: string; embedded?: boolean }) {
  const { t, i18n } = useTranslation('attendance')
  const locale = i18n.language
  const [params, setParams] = useSearchParams()
  const date = params.get('date') ?? todayIso()
  const q = useQuery({ queryKey: ['sessions-on', date], queryFn: () => attendanceApi.sessionsOn(date) })
  const n = (v: number) => formatNumber(v, locale)

  const shift = (days: number) => {
    const d = new Date(`${date}T12:00:00+03:00`)
    d.setDate(d.getDate() + days)
    setParams({ date: new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bahrain' }).format(d) }, { replace: true })
  }

  return (
    <div className="space-y-5">
      {!embedded && <PageBand title={title ?? t('title')} subtitle={subtitle ?? t('subtitle')} />}

      <div className={`${SURFACE} flex flex-wrap items-center gap-2 p-3`}>
        <SecondaryButton onClick={() => shift(-1)} aria-label="-1"><Icon name="chevron" className="size-4 ltr:rotate-180" /></SecondaryButton>
        <label htmlFor="att-date" className="sr-only">{t('date')}</label>
        <input id="att-date" type="date" value={date} onChange={(e) => e.target.value && setParams({ date: e.target.value }, { replace: true })}
          className={inputClass('md', 'tabular-nums')} />
        <SecondaryButton onClick={() => shift(1)} aria-label="+1"><Icon name="chevron" className="size-4 rtl:rotate-180" /></SecondaryButton>
        {date !== todayIso() && <SecondaryButton onClick={() => setParams({}, { replace: true })}>{t('today')}</SecondaryButton>}
        <p className="ms-auto text-sm text-ink/60">{formatDate(date, locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })} · {formatHijri(date, locale)}</p>
      </div>

      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.length === 0 ? (
        <EmptyCard icon="attendance" title={t('no_sessions')} body={t('no_sessions_body')} />
      ) : (
        <ul className="grid gap-3 *:min-w-0 md:grid-cols-2">
          {q.data.map((s) => (
            <li key={s.id}>
              <Link to={`${basePath}/${s.id}`} className={`${SURFACE} block p-4 transition hover:border-brand-500/40 hover:shadow`}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p dir="auto" className="truncate font-semibold text-ink">{s.lesson?.name}</p>
                    <p dir="auto" className="truncate text-sm text-ink/55">{s.lesson?.teacher?.name}{s.location && <> · {s.location.name}</>}</p>
                  </div>
                  <span className="shrink-0 text-sm tabular-nums text-ink/70">{formatTime(s.start_time, locale)}–{formatTime(s.end_time, locale)}</span>
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                  {s.status === 'cancelled' ? <Badge tone="muted">{t('cancelled')}</Badge> : s.attendance_taken ? <Badge tone="brand"><Icon name="check" className="size-3.5" />{t('taken')}</Badge> : <Badge tone="gold">{t('not_taken')}</Badge>}
                  {s.is_location_override && <Badge tone="info"><Icon name="pin" className="size-3.5" />{t('hall_changed')}</Badge>}
                  {s.attendance_summary && s.attendance_taken && <span className="text-xs text-ink/55">{t('present_absent', { present: n(s.attendance_summary.present), absent: n(s.attendance_summary.absent) })}</span>}
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
