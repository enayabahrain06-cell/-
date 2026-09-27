import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { attendanceMessagingApi, type MessagingRules } from '../../api/messages'
import { parseApiError } from '../../api/client'
import { Card, CardTitle, ErrorState, LoadingState, Notice, PrimaryButton, TextInput } from '../../components/ui'

const DAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'] as const

/** Section 23 messaging rules: reminders, absence notices, quiet hours and replies. */
export default function RulesTab() {
  const { t } = useTranslation('messages')
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['messaging-rules'], queryFn: attendanceMessagingApi.rules })
  const [f, setF] = useState<MessagingRules | null>(null)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  useEffect(() => { if (q.data) setF(q.data) }, [q.data])
  const save = useMutation({
    mutationFn: () => attendanceMessagingApi.saveRules(f!),
    onSuccess: (r) => { setNotice({ tone: 'success', text: t('rules.saved') }); qc.setQueryData(['messaging-rules'], r.data) },
    onError: (e) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })
  if (q.isLoading || (!f && !q.isError)) return <LoadingState />
  if (q.isError || !f) return <ErrorState onRetry={() => void q.refetch()} />
  const set = <K extends keyof MessagingRules>(k: K, v: MessagingRules[K]) => setF({ ...f, [k]: v })
  const toggle = (k: keyof MessagingRules, label: string) => (
    <label className="flex items-center gap-2 text-sm text-ink/80"><input type="checkbox" className="size-4 accent-brand-600" checked={Boolean(f[k])} onChange={(e) => set(k, e.target.checked as never)} />{label}</label>
  )
  const num = (k: keyof MessagingRules, label: string, min: number, max: number) => (
    <TextInput type="number" min={min} max={max} label={label} value={String(f[k] ?? '')} onChange={(e) => set(k, Number(e.target.value) as never)} />
  )

  return (
    <div className="space-y-5">
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <div className="grid gap-5 lg:grid-cols-2">
        <Card>
          <CardTitle>{t('rules.reminders')}</CardTitle>
          <div className="space-y-4">
            <div className="grid items-end gap-3 sm:grid-cols-2">{toggle('reminder_1_enabled', t('rules.reminder_1'))}{num('reminder_1_minutes', t('rules.minutes_before'), 15, 1440)}</div>
            <div className="grid items-end gap-3 sm:grid-cols-2">{toggle('reminder_2_enabled', t('rules.reminder_2'))}{num('reminder_2_minutes', t('rules.minutes_before'), 5, 720)}</div>
          </div>
        </Card>
        <Card>
          <CardTitle>{t('rules.absence')}</CardTitle>
          <div className="space-y-4">
            {toggle('absence_enabled', t('rules.absence'))}
            {toggle('repeated_absence_enabled', t('rules.repeated'))}
            <div className="grid gap-3 sm:grid-cols-3">
              {num('repeated_absence_count', t('rules.repeated_count'), 2, 20)}
              {num('repeated_absence_days', t('rules.repeated_days'), 7, 180)}
              {num('repeated_absence_throttle_days', t('rules.throttle'), 1, 90)}
            </div>
            {toggle('location_change_enabled', t('rules.location_change'))}
            {toggle('session_cancelled_enabled', t('rules.session_cancelled'))}
          </div>
        </Card>
        <Card>
          <CardTitle>{t('rules.quiet')}</CardTitle>
          <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
              <TextInput type="time" label={t('rules.quiet_start')} value={f.quiet_start} onChange={(e) => set('quiet_start', e.target.value)} />
              <TextInput type="time" label={t('rules.quiet_end')} value={f.quiet_end} onChange={(e) => set('quiet_end', e.target.value)} />
            </div>
            <fieldset>
              <legend className="mb-1.5 text-sm font-medium text-ink/75">{t('rules.quiet_days')}</legend>
              <div className="flex flex-wrap gap-2">
                {DAYS.map((d) => (
                  <label key={d} className="flex items-center gap-1.5 rounded-lg border border-ink/12 px-2.5 py-1.5 text-sm text-ink/80">
                    <input type="checkbox" className="size-4 accent-brand-600" checked={f.quiet_days.includes(d)} onChange={(e) => set('quiet_days', e.target.checked ? [...f.quiet_days, d] : f.quiet_days.filter((x) => x !== d))} />
                    {t(`rules.days.${d}`)}
                  </label>
                ))}
              </div>
            </fieldset>
            <p className="text-xs text-ink/55">{t('rules.quiet_hint')}</p>
          </div>
        </Card>
        <Card>
          <CardTitle>{t('rules.other')}</CardTitle>
          <div className="grid gap-3 sm:grid-cols-2">
            {num('student_copy_min_age', t('rules.student_copy'), 6, 25)}
            <TextInput label={t('rules.supervisor_phone')} dir="ltr" value={f.supervisor_phone ?? ''} onChange={(e) => set('supervisor_phone', e.target.value)} />
            {num('auto_reply_hours', t('rules.auto_reply_hours'), 1, 168)}
            {num('invalid_after_failures', t('rules.invalid_after'), 1, 10)}
          </div>
        </Card>
      </div>
      <div className="flex justify-end"><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{t('rules.save')}</PrimaryButton></div>
    </div>
  )
}
