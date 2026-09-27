import { useEffect, useId, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { evaluationsApi, type NewIssue } from '../../api/evaluations'
import { parseApiError } from '../../api/client'
import SelectField from '../../components/SelectField'
import { Modal, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput } from '../../components/ui'

/** Modal to open a difficulty (prefilled from a suggestion). Modal handles Esc and scrolling; focus goes to the category. */
export default function IssueDialog({ studentId, studentName, initial, onClose, onDone }: {
  studentId: number
  studentName: string
  initial: Partial<NewIssue>
  onClose: () => void
  onDone: () => void
}) {
  const { t } = useTranslation('evaluation')
  const options = useQuery({ queryKey: ['issue-options'], queryFn: evaluationsApi.issueOptions, staleTime: 10 * 60_000 })
  const [form, setForm] = useState<NewIssue>({ category: 'tajweed', severity: 'medium', description: '', action_plan: '', subcategory: null, ...initial })
  const [error, setError] = useState<string | null>(null)
  const firstRef = useRef<HTMLSelectElement>(null)
  const formId = useId()

  useEffect(() => firstRef.current?.focus(), [])

  const save = useMutation({
    mutationFn: () => evaluationsApi.openIssue(studentId, { ...form, subcategory: form.category === 'tajweed' ? form.subcategory || null : null, action_plan: form.action_plan || null, next_follow_up_date: form.next_follow_up_date || null }),
    onSuccess: onDone,
    onError: (e) => setError(parseApiError(e).message),
  })

  return (
    <Modal title={t('issue.title', { name: studentName })} onClose={onClose} footer={<>
      <SecondaryButton onClick={onClose}>{t('issue.cancel')}</SecondaryButton>
      <PrimaryButton type="submit" form={formId} loading={save.isPending}>{t('issue.save')}</PrimaryButton>
    </>}>
      <form id={formId} onSubmit={(e) => { e.preventDefault(); save.mutate() }} className="space-y-4">
        {error && <Notice tone="error">{error}</Notice>}
        <div className="grid gap-3 sm:grid-cols-2">
          <SelectField ref={firstRef} label={t('issue.category')} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} options={options.data?.categories ?? []} />
          <SelectField label={t('issue.severity')} value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })} options={options.data?.severities ?? []} />
          {form.category === 'tajweed' && (
            <SelectField className="sm:col-span-2" label={t('issue.aspect')} value={form.subcategory ?? ''} onChange={(e) => setForm({ ...form, subcategory: e.target.value || null })}
              options={[{ value: '', label: '—' }, ...(options.data?.tajweed_aspects ?? [])]} />
          )}
        </div>
        <TextArea label={t('issue.description')} required minLength={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} dir="auto" />
        <TextArea label={t('issue.action_plan')} value={form.action_plan ?? ''} onChange={(e) => setForm({ ...form, action_plan: e.target.value })} dir="auto" />
        <TextInput label={t('issue.follow_up')} type="date" value={form.next_follow_up_date ?? ''} onChange={(e) => setForm({ ...form, next_follow_up_date: e.target.value })} />
      </form>
    </Modal>
  )
}
