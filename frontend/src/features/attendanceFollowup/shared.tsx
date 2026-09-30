import { isAxiosError } from 'axios'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import { ErrorState, Notice, SURFACE, SecondaryButton, inputClass, type Tone } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'

/** Today in Bahrain as YYYY-MM-DD (the attendance day). */
// eslint-disable-next-line react-refresh/only-export-components
export const todayIso = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bahrain' }).format(new Date())

// eslint-disable-next-line react-refresh/only-export-components
export function shiftIso(date: string, days: number): string {
  const d = new Date(`${date}T12:00:00+03:00`)
  d.setDate(d.getDate() + days)
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bahrain' }).format(d)
}

/** Tone of an attendance status (students and staff share the four statuses). */
// eslint-disable-next-line react-refresh/only-export-components
export const STATUS_TONE: Record<'present' | 'late' | 'absent' | 'excused', Tone> = { present: 'brand', late: 'gold', absent: 'danger', excused: 'info' }

/** Previous day / date / next day / today, in one wrapping row. */
export function DateStepper({ id, value, onChange, label }: { id: string; value: string; onChange: (v: string) => void; label: string }) {
  const { t, i18n } = useTranslation('attendanceFollowup')
  return (
    <div className="flex flex-wrap items-center gap-2">
      <SecondaryButton onClick={() => onChange(shiftIso(value, -1))} aria-label={t('prev_day')}><Icon name="chevron" className="size-4 ltr:rotate-180" /></SecondaryButton>
      <label htmlFor={id} className="sr-only">{label}</label>
      <input id={id} type="date" value={value} onChange={(e) => e.target.value && onChange(e.target.value)} className={inputClass('md', 'w-40 tabular-nums')} />
      <SecondaryButton onClick={() => onChange(shiftIso(value, 1))} aria-label={t('next_day')}><Icon name="chevron" className="size-4 rtl:rotate-180" /></SecondaryButton>
      {value !== todayIso() && <SecondaryButton onClick={() => onChange(todayIso())}>{t('today')}</SecondaryButton>}
      <p className="basis-full text-sm text-ink/60 sm:ms-2 sm:basis-auto">{formatDate(value, i18n.language, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</p>
    </div>
  )
}

/** Error of a term-bound query: "no term yet" (422) as an info notice, anything else as an error with retry. */
export function QueryError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  if (isAxiosError(error) && error.response?.status === 422) return <Notice tone="info">{parseApiError(error).message}</Notice>
  return <ErrorState message={parseApiError(error).message} onRetry={onRetry} />
}

/** A KPI tile: label and a big number. */
export function Stat({ label, value, tone = 'ink' }: { label: string; value: number | string; tone?: 'ink' | 'brand' | 'danger' | 'gold' }) {
  const { i18n } = useTranslation()
  const color = { ink: 'text-ink', brand: 'text-brand-700', danger: 'text-danger', gold: 'text-gold-700' }[tone]
  return (
    <div className={`${SURFACE} p-4`}>
      <p className="text-sm text-ink/60">{label}</p>
      <p className={`mt-1 text-2xl font-semibold tabular-nums ${color}`}>{typeof value === 'number' ? formatNumber(value, i18n.language) : value}</p>
    </div>
  )
}
