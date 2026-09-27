import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { quranApi, type ProgressEntry } from '../api/attendance'
import { formatNumber } from '../lib/format'
import { IconButton, inputClass } from './ui'

/**
 * "Two taps" ledger entry: pick the surah, then the ayah range (defaults to the whole surah).
 * The server validates the range again; this only keeps the inputs inside the surah.
 */
export default function QuranRangePicker({ value, onChange, onRemove, idPrefix }: {
  value: ProgressEntry
  onChange: (v: ProgressEntry) => void
  onRemove?: () => void
  idPrefix: string
}) {
  const { t, i18n } = useTranslation('common')
  const surahs = useQuery({ queryKey: ['surahs', i18n.language], queryFn: quranApi.surahs, staleTime: Infinity })
  const current = surahs.data?.find((s) => s.number === value.surah_number)
  const max = current?.ayah_count ?? 286
  const clamp = (v: number) => Math.min(max, Math.max(1, Number.isFinite(v) ? v : 1))

  return (
    <div className="flex flex-wrap items-end gap-2 rounded-xl border border-ink/10 bg-page/40 p-2">
      <label className="sr-only" htmlFor={`${idPrefix}-type`}>{t('quran.type')}</label>
      <select id={`${idPrefix}-type`} value={value.type} onChange={(e) => onChange({ ...value, type: e.target.value as ProgressEntry['type'] })}
        className={inputClass('sm')}>
        <option value="memorized">{t('quran.memorized')}</option>
        <option value="revised">{t('quran.revised')}</option>
      </select>

      <label className="sr-only" htmlFor={`${idPrefix}-surah`}>{t('quran.surah')}</label>
      <select id={`${idPrefix}-surah`} value={value.surah_number}
        onChange={(e) => {
          const n = Number(e.target.value)
          const count = surahs.data?.find((s) => s.number === n)?.ayah_count ?? 1
          onChange({ ...value, surah_number: n, from_ayah: 1, to_ayah: count })
        }}
        className={inputClass('sm', 'min-w-36 flex-1')}>
        {(surahs.data ?? []).map((s) => (
          <option key={s.number} value={s.number}>{formatNumber(s.number, i18n.language)}. {s.name}</option>
        ))}
      </select>

      <span className="inline-flex items-center gap-1 text-sm text-ink/60">
        <label htmlFor={`${idPrefix}-from`}>{t('quran.from')}</label>
        <input id={`${idPrefix}-from`} type="number" inputMode="numeric" min={1} max={max} value={value.from_ayah}
          onChange={(e) => { const f = clamp(Number(e.target.value)); onChange({ ...value, from_ayah: f, to_ayah: Math.max(f, value.to_ayah) }) }}
          className={inputClass('sm', 'w-16 text-center tabular-nums')} />
        <label htmlFor={`${idPrefix}-to`}>{t('quran.to')}</label>
        <input id={`${idPrefix}-to`} type="number" inputMode="numeric" min={value.from_ayah} max={max} value={value.to_ayah}
          onChange={(e) => onChange({ ...value, to_ayah: Math.max(value.from_ayah, clamp(Number(e.target.value))) })}
          className={inputClass('sm', 'w-16 text-center tabular-nums')} />
      </span>

      {onRemove && (
        <IconButton icon="close" tone="remove" label={t('quran.remove')} onClick={onRemove} />
      )}
    </div>
  )
}
