import { isAxiosError } from 'axios'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { distributionApi, type DistClass, type PlaceResult } from '../../api/distribution'
import Icon from '../../components/Icon'
import { ErrorState, Notice, SURFACE } from '../../components/ui'
import { formatNumber } from '../../lib/format'

/** Levels and the selected term's classes (with free seats). */
// eslint-disable-next-line react-refresh/only-export-components
export function useDistOptions() {
  return useQuery({ queryKey: ['distribution-options'], queryFn: distributionApi.options, staleTime: 30_000 })
}

/** 422 when no term exists yet: say so instead of a generic error. */
export function OptionsError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  return isAxiosError(error) && error.response?.status === 422
    ? <Notice tone="info">{parseApiError(error).message}</Notice>
    : <ErrorState onRetry={onRetry} />
}

/** "صف ب (٢ مقاعد شاغرة)" options for a class picker; the first option is "auto". */
// eslint-disable-next-line react-refresh/only-export-components
export function useClassOptions(classes: DistClass[]) {
  const { t, i18n } = useTranslation('distribution')
  return [
    { value: '', label: t('auto_class') },
    ...classes.map((c) => ({ value: String(c.id), label: t('class_option', { name: c.name, count: c.free_seats, seats: formatNumber(c.free_seats, i18n.language) }) })),
  ]
}

/** The outcome of a bulk action, one line per student. */
export function ResultsList({ message, results }: { message: string; results: PlaceResult[] }) {
  const { t } = useTranslation('distribution')
  const refused = results.filter((r) => !r.ok)
  return (
    <section className={`${SURFACE} space-y-3 p-4`} aria-live="polite">
      <p className="font-semibold text-ink">{message}</p>
      {refused.length > 0 && <p className="text-sm text-danger">{t('refused_title')}</p>}
      <ul className="space-y-1.5 text-sm">
        {results.map((r) => (
          <li key={r.student_id} className="flex items-start gap-2">
            <Icon name={r.ok ? 'check' : 'alert'} className={`mt-0.5 size-4 shrink-0 ${r.ok ? 'text-brand-700' : 'text-danger'}`} />
            <span dir="auto" className={r.ok ? 'text-ink/80' : 'text-danger'}>{r.message}</span>
          </li>
        ))}
      </ul>
    </section>
  )
}
