import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { hallsApi, type CalendarItem, type Hall } from '../../api/lessons'
import Icon from '../../components/Icon'
import { PageBand } from '../../components/ornaments'
import { Badge, ErrorState, LoadingState, SecondaryButton, type Tone, SURFACE } from '../../components/ui'
import { formatDate, formatTime } from '../../lib/format'
import { GENDER_TONE } from './LessonsHomePage'

const KIND_TONE: Record<CalendarItem['kind'], Tone> = { session: 'brand', override: 'info', booking: 'gold', occupied: 'muted' }

/** Week agenda of a hall (Saturday first). Other-track items arrive masked as "occupied" from the server. */
export default function HallCalendarPage() {
  const { id } = useParams()
  const hallId = Number(id)
  const { t, i18n } = useTranslation('lessons')
  const locale = i18n.language
  const [start, setStart] = useState(() => {
    const d = new Date()
    d.setDate(d.getDate() - ((d.getDay() + 1) % 7)) // back to Saturday
    return d.toISOString().slice(0, 10)
  })
  const end = (() => { const d = new Date(`${start}T12:00:00`); d.setDate(d.getDate() + 6); return d.toISOString().slice(0, 10) })()
  const q = useQuery({ queryKey: ['hall-calendar', hallId, start], queryFn: () => hallsApi.calendar(hallId, start, end) })
  const move = (days: number) => { const d = new Date(`${start}T12:00:00`); d.setDate(d.getDate() + days); setStart(d.toISOString().slice(0, 10)) }

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState onRetry={() => void q.refetch()} />
  const hall = ('data' in q.data.location ? q.data.location.data : q.data.location) as Hall
  const days = Array.from({ length: 7 }, (_, i) => { const d = new Date(`${start}T12:00:00`); d.setDate(d.getDate() + i); return d.toISOString().slice(0, 10) })

  return (
    <div className="space-y-5">
      <Link to="/lessons?tab=halls" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline"><Icon name="chevron" className="size-4 ltr:rotate-180" />{t('calendar.back')}</Link>
      <PageBand title={t('calendar.title', { name: hall.name })} subtitle={t(`gender.${hall.gender}`)}
        actions={<div className="flex gap-2">
          <SecondaryButton onClick={() => move(-7)} aria-label={t('calendar.prev')}><Icon name="chevron" className="size-4 ltr:rotate-180" /></SecondaryButton>
          <SecondaryButton onClick={() => move(7)} aria-label={t('calendar.next')}><Icon name="chevron" className="size-4 rtl:rotate-180" /></SecondaryButton>
        </div>} />
      <p className="text-sm text-ink/60">{t('calendar.week', { date: formatDate(start, locale, { day: 'numeric', month: 'long', year: 'numeric' }) })}</p>
      <ul className="grid gap-3 *:min-w-0 md:grid-cols-7">
        {days.map((d) => {
          const items = q.data.items.filter((i) => i.date === d)
          return (
            <li key={d} className={`${SURFACE} p-3`}>
              <p className="mb-2 text-sm font-semibold text-ink">{formatDate(d, locale, { weekday: 'short', day: 'numeric' })}</p>
              {items.length === 0 ? <p className="text-xs text-ink/40">{t('calendar.empty_day')}</p> : (
                <ul className="space-y-2">
                  {items.map((i, k) => (
                    <li key={k} className={`rounded-lg border-s-4 px-2 py-1.5 text-xs ${i.masked ? 'border-ink/20 bg-ink/5 text-ink/55' : 'border-brand-600 bg-brand-50/60 text-ink'}`}>
                      <p className="tabular-nums text-ink/60">{formatTime(i.start_time, locale)}–{formatTime(i.end_time, locale)}</p>
                      <p dir="auto" className="font-medium">{i.title}</p>
                      <div className="mt-1 flex flex-wrap gap-1">
                        <Badge tone={KIND_TONE[i.kind]}>{t(`calendar.kinds.${i.kind}`)}</Badge>
                        {i.gender && <Badge tone={GENDER_TONE[i.gender]}>{t(`gender.${i.gender}`)}</Badge>}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
