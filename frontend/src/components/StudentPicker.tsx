import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { studentsApi, type StudentSummary } from '../api/students'
import Avatar from './Avatar'

/** Search-as-you-type student picker (name, number or phone). Results are track-scoped by the API. */
export default function StudentPicker({ value, onChange, label, gender }: { value: StudentSummary | null; onChange: (s: StudentSummary | null) => void; label: string; gender?: string }) {
  const { t } = useTranslation('common')
  const [q, setQ] = useState('')
  const results = useQuery({ queryKey: ['student-picker', q, gender], queryFn: () => studentsApi.list({ search: q, gender, per_page: 8 }), enabled: q.trim().length >= 2 && !value })

  if (value) {
    return (
      <div>
        <p className="mb-1.5 text-sm font-medium text-ink/75">{label}</p>
        <div className="flex items-center gap-3 rounded-xl border border-brand-600/40 bg-brand-50/50 px-3 py-2">
          <Avatar name={value.full_name} initial={value.initial} src={value.photo_url} gender={value.gender} size="sm" />
          <span className="min-w-0 flex-1">
            <span dir="auto" className="block truncate font-medium text-ink">{value.full_name}</span>
            <span className="block text-xs tabular-nums text-ink/55">{value.student_no}</span>
          </span>
          <button type="button" className="text-sm text-brand-700 hover:underline" onClick={() => onChange(null)}>{t('picker.change')}</button>
        </div>
      </div>
    )
  }

  return (
    <div className="relative">
      <label htmlFor="student-picker" className="mb-1.5 block text-sm font-medium text-ink/75">{label}</label>
      <input id="student-picker" type="search" autoComplete="off" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('picker.placeholder')}
        className="block w-full rounded-xl border border-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100" />
      {results.data && q.trim().length >= 2 && (
        <ul role="listbox" className="absolute inset-x-0 z-10 mt-1 max-h-64 overflow-y-auto rounded-xl border border-ink/10 bg-white shadow-lg">
          {results.data.data.length === 0 ? <li className="px-3 py-2 text-sm text-ink/50">{t('picker.none')}</li> : results.data.data.map((s) => (
            <li key={s.id}>
              <button type="button" role="option" aria-selected={false} onClick={() => { onChange(s); setQ('') }} className="flex w-full items-center gap-3 px-3 py-2 text-start hover:bg-brand-50">
                <Avatar name={s.full_name} initial={s.initial} src={s.photo_url} gender={s.gender} size="sm" />
                <span className="min-w-0">
                  <span dir="auto" className="block truncate text-sm text-ink">{s.full_name}</span>
                  <span className="block text-xs tabular-nums text-ink/50">{s.student_no} · {s.guardian_phone}</span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
