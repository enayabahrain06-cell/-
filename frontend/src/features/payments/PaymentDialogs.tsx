import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { METHODS, paymentsApi, type Method } from '../../api/payments'
import { studentsApi, type StudentSummary } from '../../api/students'
import { parseApiError, type FieldErrors } from '../../api/client'
import SelectField from '../../components/SelectField'
import { useTerm } from '../../app/term'
import StudentPicker from '../../components/StudentPicker'
import { Modal, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput } from '../../components/ui'
import { formatMoney } from '../../lib/format'
import { toLatinDigits } from '../../lib/phone'

function useInvalidate() {
  const qc = useQueryClient()
  return () => ['payments', 'invoices', 'refunds', 'finance', 'student-wallet', 'students', 'dashboard'].forEach((k) => void qc.invalidateQueries({ queryKey: [k] }))
}

function WalletLine({ student }: { student: StudentSummary }) {
  const { t, i18n } = useTranslation('payments')
  const w = useQuery({ queryKey: ['student-wallet', student.id], queryFn: () => studentsApi.wallet(student.id) })
  if (!w.data) return null
  return (
    <p className="text-sm text-ink/65">
      <span className={w.data.is_due ? 'font-semibold text-danger' : ''}>{t('form.balance', { amount: formatMoney(w.data.balance_fils, i18n.language) })}</span>
      {' · '}{t('form.outstanding', { amount: formatMoney(w.data.outstanding_fils, i18n.language) })}
    </p>
  )
}

const errOf = (errors: FieldErrors, ...keys: string[]) => keys.map((k) => errors[k]?.[0]).find(Boolean)

export function RecordPaymentDialog({ initial, onClose, onDone }: { initial?: StudentSummary | null; onClose: () => void; onDone: (msg: string) => void }) {
  const { t } = useTranslation('payments')
  const invalidate = useInvalidate()
  const [student, setStudent] = useState<StudentSummary | null>(initial ?? null)
  const [form, setForm] = useState({ amount: '', method: 'cash' as Method, reference: '', note: '', paid_at: new Date().toISOString().slice(0, 10), notify: true })
  const [file, setFile] = useState<File | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [msg, setMsg] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => {
      const fd = new FormData()
      fd.append('student_id', String(student!.id))
      fd.append('amount', toLatinDigits(form.amount))
      fd.append('method', form.method)
      if (form.reference) fd.append('reference', form.reference)
      if (form.note) fd.append('note', form.note)
      fd.append('paid_at', form.paid_at)
      fd.append('notify', form.notify ? '1' : '0')
      if (file) fd.append('receipt_image', file)
      return paymentsApi.record(fd)
    },
    onSuccess: () => { invalidate(); onDone(t('form.saved')) },
    onError: (e) => { const p = parseApiError(e); setErrors(p.fields); setMsg(p.message) },
  })

  return (
    <Modal title={t('record')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton disabled={!student || !form.amount} loading={save.isPending} onClick={() => save.mutate()}>{t('form.save')}</PrimaryButton></>}>
      {msg && <Notice tone="error">{errOf(errors, 'amount_fils', 'student_id', 'method', 'receipt_image') ?? msg}</Notice>}
      <StudentPicker label={t('form.student')} value={student} onChange={setStudent} />
      {student && <WalletLine student={student} />}
      <div className="grid gap-4 sm:grid-cols-2">
        <TextInput label={t('form.amount')} inputMode="decimal" dir="ltr" placeholder="0.000" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
        <SelectField label={t('form.method')} value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value as Method })} options={METHODS.map((m) => ({ value: m, label: t(`methods.${m}`) }))} />
        <TextInput label={t('form.reference')} dir="ltr" value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} />
        <TextInput label={t('form.paid_at')} type="date" value={form.paid_at} onChange={(e) => setForm({ ...form, paid_at: e.target.value })} />
      </div>
      <TextArea label={t('form.note')} rows={2} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} dir="auto" />
      <div>
        <label htmlFor="receipt-image" className="mb-1.5 block text-sm font-medium text-ink/75">{t('form.receipt_image')}</label>
        <input id="receipt-image" type="file" accept="image/jpeg,image/png,application/pdf" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-sm" />
      </div>
      <label className="flex items-center gap-2 text-sm text-ink/80"><input type="checkbox" className="size-4 accent-brand-600" checked={form.notify} onChange={(e) => setForm({ ...form, notify: e.target.checked })} />{t('form.notify')}</label>
      <p className="text-xs text-ink/50">{t('form.settles')}</p>
    </Modal>
  )
}

export function InvoiceDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation('payments')
  // Defaults to the term chosen in the top bar (the current one when "all terms" is selected).
  const { terms, selected, current } = useTerm()
  const invalidate = useInvalidate()
  const [student, setStudent] = useState<StudentSummary | null>(null)
  const [form, setForm] = useState(() => ({ amount: '', description: '', due_date: new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10), term: '', academic_term_id: selected?.id ?? current?.id ?? null as number | null }))
  const [error, setError] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => paymentsApi.createInvoice({ student_id: student!.id, amount: toLatinDigits(form.amount), description: form.description, due_date: form.due_date, term: form.term || undefined, academic_term_id: form.academic_term_id ?? undefined }),
    onSuccess: () => { invalidate(); onDone() },
    onError: (e) => { const p = parseApiError(e); setError(errOf(p.fields, 'amount_fils', 'description', 'due_date') ?? p.message) },
  })
  return (
    <Modal title={t('new_invoice')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton disabled={!student || !form.amount || !form.description} loading={save.isPending} onClick={() => save.mutate()}>{t('form.save')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      <StudentPicker label={t('form.student')} value={student} onChange={setStudent} />
      <TextInput label={t('form.description')} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} dir="auto" />
      <div className="grid gap-4 sm:grid-cols-3">
        <TextInput label={t('form.amount')} inputMode="decimal" dir="ltr" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
        <TextInput label={t('form.due_date')} type="date" value={form.due_date} onChange={(e) => setForm({ ...form, due_date: e.target.value })} />
        {terms.length > 0 ? (
          <SelectField label={t('form.term')} value={String(form.academic_term_id ?? '')} onChange={(e) => setForm({ ...form, academic_term_id: Number(e.target.value) })}
            options={terms.map((x) => ({ value: String(x.id), label: x.name }))} />
        ) : (
          <TextInput label={t('form.term')} value={form.term} onChange={(e) => setForm({ ...form, term: e.target.value })} />
        )}
      </div>
    </Modal>
  )
}

export function RefundDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation('payments')
  const invalidate = useInvalidate()
  const [student, setStudent] = useState<StudentSummary | null>(null)
  const [form, setForm] = useState({ amount: '', method: 'cash' as Method, reference: '', note: '' })
  const [error, setError] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => paymentsApi.refund({ student_id: student!.id, amount: toLatinDigits(form.amount), method: form.method, reference: form.reference || undefined, note: form.note }),
    onSuccess: () => { invalidate(); onDone() },
    onError: (e) => { const p = parseApiError(e); setError(errOf(p.fields, 'amount_fils', 'note') ?? p.message) },
  })
  return (
    <Modal title={t('new_refund')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton disabled={!student || !form.amount || form.note.length < 3} loading={save.isPending} onClick={() => save.mutate()}>{t('form.save')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      <p className="text-xs text-ink/55">{t('form.refund_hint')}</p>
      <StudentPicker label={t('form.student')} value={student} onChange={setStudent} />
      {student && <WalletLine student={student} />}
      <div className="grid gap-4 sm:grid-cols-2">
        <TextInput label={t('form.amount')} inputMode="decimal" dir="ltr" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
        <SelectField label={t('form.method')} value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value as Method })} options={METHODS.map((m) => ({ value: m, label: t(`methods.${m}`) }))} />
      </div>
      <TextInput label={t('form.reference')} dir="ltr" value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} />
      <TextArea label={t('form.note_required')} rows={2} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} dir="auto" />
    </Modal>
  )
}

export function AdjustDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation('payments')
  const invalidate = useInvalidate()
  const [student, setStudent] = useState<StudentSummary | null>(null)
  const [form, setForm] = useState({ amount: '', note: '', reference: '' })
  const [error, setError] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => paymentsApi.adjust(student!.id, { amount: toLatinDigits(form.amount), note: form.note, reference: form.reference || undefined }),
    onSuccess: () => { invalidate(); onDone() },
    onError: (e) => { const p = parseApiError(e); setError(errOf(p.fields, 'amount_fils', 'note') ?? p.message) },
  })
  return (
    <Modal title={t('adjust')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton disabled={!student || !form.amount || form.note.length < 3} loading={save.isPending} onClick={() => save.mutate()}>{t('form.save')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      <p className="text-xs text-ink/55">{t('form.adjust_hint')}</p>
      <StudentPicker label={t('form.student')} value={student} onChange={setStudent} />
      {student && <WalletLine student={student} />}
      <TextInput label={t('form.adjust_amount')} inputMode="decimal" dir="ltr" placeholder="-5.000" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
      <TextArea label={t('form.note_required')} rows={2} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} dir="auto" />
    </Modal>
  )
}
