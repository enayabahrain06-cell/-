import { useEffect, useId, useState, type ReactNode } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { certificatesApi, type Certificate, type CertificateGrade, type CertificateType } from '../../api/certificates'
import { parseApiError } from '../../api/client'
import type { StudentSummary } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import StudentPicker from '../../components/StudentPicker'
import { StarSpinner } from '../../components/ornaments'
import { Modal, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'
import { IssuedDate, StatusBadge, displayTitle, useInvalidateCertificates, useObjectUrl } from './shared'

export function useCertificateOptions() {
  const { i18n } = useTranslation()
  return useQuery({ queryKey: ['certificates', 'options', i18n.language], queryFn: certificatesApi.options, staleTime: 5 * 60_000 })
}

/* ------------------------------------------------------------------ Issue */

/** Issue drafts for one or more students (same type, achievement, grade). `student` preselects one (profile tab). */
export function IssueCertificateDialog({ student, onClose, onDone }: { student?: StudentSummary | null; onClose: () => void; onDone: (message: string) => void }) {
  const { t } = useTranslation('certificates')
  const options = useCertificateOptions()
  const invalidate = useInvalidateCertificates()
  const [students, setStudents] = useState<StudentSummary[]>(student ? [student] : [])
  const [type, setType] = useState<CertificateType>('completion')
  const [achievement, setAchievement] = useState('')
  const [grade, setGrade] = useState<CertificateGrade | ''>('')
  const [title, setTitle] = useState('')
  const [error, setError] = useState<{ message: string; fields: Record<string, string[]> } | null>(null)

  const issue = useMutation({
    mutationFn: () => certificatesApi.issue({ student_ids: students.map((s) => s.id), type, achievement: achievement.trim(), grade: grade || null, title: title.trim() || null }),
    onSuccess: (r) => { invalidate(); onDone(r.message) },
    onError: (e) => setError(parseApiError(e)),
  })

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    if (students.length === 0) {
      setError({ message: t('issue.pick_one'), fields: {} })
      return
    }
    setError(null)
    issue.mutate()
  }
  const fieldError = (k: string) => error?.fields[k]?.[0]
  const o = options.data

  return (
    <Modal title={t('issue.title')} onClose={onClose} wide
      footer={<>
        <SecondaryButton onClick={onClose}>{t('actions.cancel')}</SecondaryButton>
        <PrimaryButton type="submit" form="issue-certificate" loading={issue.isPending}>{t('issue.submit')}</PrimaryButton>
      </>}>
      {!o ? <div className="grid place-items-center py-10"><StarSpinner className="size-8 text-brand-600" /></div> : (
        <form id="issue-certificate" className="space-y-4" onSubmit={submit} noValidate>
          <Notice tone="info">{o.require_approval ? t('issue.drafts_note') : t('issue.direct_note')}</Notice>
          {error && <Notice tone="error">{error.message}</Notice>}

          <fieldset className="space-y-2">
            <legend className="mb-1 text-sm font-medium text-ink/75">{t('issue.students')}</legend>
            {students.length > 0 && (
              <ul className="flex flex-wrap gap-2">
                {students.map((s) => (
                  <li key={s.id} className="inline-flex items-center gap-2 rounded-full border border-brand-600/30 bg-brand-50/60 py-1 pe-1 ps-1.5 text-sm">
                    <Avatar name={s.full_name} initial={s.initial} src={s.photo_url} gender={s.gender} size="sm" />
                    <span dir="auto" className="text-ink">{s.full_name}</span>
                    <span className="text-xs tabular-nums text-ink/50">{s.student_no}</span>
                    <button type="button" onClick={() => setStudents((l) => l.filter((x) => x.id !== s.id))} aria-label={t('issue.remove_student', { name: s.full_name })}
                      className="rounded-full p-1 text-ink/50 hover:bg-white hover:text-danger"><Icon name="close" className="size-3.5" /></button>
                  </li>
                ))}
              </ul>
            )}
            <StudentPicker value={null} label={t('issue.search_student')} onChange={(s) => s && setStudents((l) => (l.some((x) => x.id === s.id) ? l : [...l, s]))} />
            <p className="text-xs text-ink/55">{t('issue.students_hint')}</p>
            {fieldError('student_ids') && <p className="text-sm text-danger">{fieldError('student_ids')}</p>}
          </fieldset>

          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField label={t('issue.type')} value={type} onChange={(e) => setType(e.target.value as CertificateType)} options={o.types} />
            <SelectField label={t('issue.grade')} value={grade} onChange={(e) => setGrade(e.target.value as CertificateGrade | '')}
              options={[{ value: '', label: t('issue.no_grade') }, ...o.grades]} />
          </div>
          <div>
            <TextInput label={t('issue.achievement')} value={achievement} onChange={(e) => setAchievement(e.target.value)} placeholder={t('issue.achievement_placeholder')} required maxLength={255} dir="auto" />
            <p className={`mt-1 text-xs ${fieldError('achievement') ? 'text-danger' : 'text-ink/55'}`}>{fieldError('achievement') ?? t('issue.achievement_hint')}</p>
          </div>
          <div>
            <TextInput label={t('issue.custom_title')} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={200} dir="auto" />
            <p className={`mt-1 text-xs ${fieldError('title') ? 'text-danger' : 'text-ink/55'}`}>{fieldError('title') ?? t('issue.custom_title_hint')}</p>
          </div>
        </form>
      )}
    </Modal>
  )
}

/* ------------------------------------------------------------------ Edit draft */

export function EditDraftDialog({ c, onClose, onDone }: { c: Certificate; onClose: () => void; onDone: (m: string) => void }) {
  const { t } = useTranslation('certificates')
  const options = useCertificateOptions()
  const invalidate = useInvalidateCertificates()
  const [achievement, setAchievement] = useState(c.achievement)
  const [title, setTitle] = useState(c.title)
  const [grade, setGrade] = useState<CertificateGrade | ''>(c.grade ?? '')
  const [issuedOn, setIssuedOn] = useState(c.issued_on ?? '')
  const [error, setError] = useState<{ message: string; fields: Record<string, string[]> } | null>(null)

  const save = useMutation({
    mutationFn: () => certificatesApi.update(c.id, { achievement: achievement.trim(), title: title.trim() || null, grade: grade || null, ...(issuedOn ? { issued_on: issuedOn } : {}) }),
    onSuccess: (r) => { invalidate(); onDone(r.message) },
    onError: (e) => setError(parseApiError(e)),
  })
  const fe = (k: string) => error?.fields[k]?.[0]

  return (
    <Modal title={t('edit.title', { no: c.certificate_no })} onClose={onClose}
      footer={<>
        <SecondaryButton onClick={onClose}>{t('actions.cancel')}</SecondaryButton>
        <PrimaryButton type="submit" form="edit-certificate" loading={save.isPending}>{t('actions.save')}</PrimaryButton>
      </>}>
      <form id="edit-certificate" className="space-y-4" onSubmit={(e) => { e.preventDefault(); setError(null); save.mutate() }}>
        {error && <Notice tone="error">{error.message}</Notice>}
        <FieldWithError error={fe('title')}><TextInput label={t('edit.title_field')} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={200} dir="auto" /></FieldWithError>
        <FieldWithError error={fe('achievement')}><TextInput label={t('issue.achievement')} value={achievement} onChange={(e) => setAchievement(e.target.value)} required maxLength={255} dir="auto" /></FieldWithError>
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField label={t('issue.grade')} value={grade} onChange={(e) => setGrade(e.target.value as CertificateGrade | '')}
            options={[{ value: '', label: t('issue.no_grade') }, ...(options.data?.grades ?? [])]} />
          <FieldWithError error={fe('issued_on')}><TextInput type="date" label={t('edit.issued_on')} value={issuedOn} onChange={(e) => setIssuedOn(e.target.value)} dir="ltr" /></FieldWithError>
        </div>
      </form>
    </Modal>
  )
}

function FieldWithError({ error, children }: { error?: string; children: ReactNode }) {
  return <div>{children}{error && <p className="mt-1 text-sm text-danger">{error}</p>}</div>
}

/* ------------------------------------------------------------------ Revoke */

export function RevokeDialog({ c, onClose, onDone }: { c: Certificate; onClose: () => void; onDone: (m: string) => void }) {
  const { t } = useTranslation('certificates')
  const invalidate = useInvalidateCertificates()
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const revoke = useMutation({
    mutationFn: () => certificatesApi.revoke(c.id, reason.trim()),
    onSuccess: (r) => { invalidate(); onDone(r.message) },
    onError: (e) => { const p = parseApiError(e); setError(p.fields.reason?.[0] ?? p.message) },
  })
  return (
    <Modal title={t('revoke.title', { no: c.certificate_no })} onClose={onClose}
      footer={<>
        <SecondaryButton onClick={onClose}>{t('actions.cancel')}</SecondaryButton>
        <PrimaryButton tone="danger" type="submit" form="revoke-certificate" loading={revoke.isPending} disabled={reason.trim().length < 3}>{t('revoke.submit')}</PrimaryButton>
      </>}>
      <form id="revoke-certificate" className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (reason.trim().length >= 3) revoke.mutate() }}>
        <p className="text-sm text-ink/70"><span dir="auto" className="font-medium text-ink">{c.student?.full_name}</span> · {displayTitle(c)}</p>
        <Notice tone="error">{t('revoke.warning')}</Notice>
        <TextArea label={t('revoke.reason')} value={reason} onChange={(e) => setReason(e.target.value)} required minLength={3} maxLength={500} dir="auto" />
        <p className={`text-xs ${error ? 'text-danger' : 'text-ink/55'}`}>{error ?? t('revoke.reason_hint')}</p>
      </form>
    </Modal>
  )
}

/* ------------------------------------------------------------------ Actions */

export type ActionKind = 'view' | 'approve' | 'edit' | 'delete' | 'revoke' | 'send' | 'download' | 'print' | 'share'

/** Which actions a certificate offers to this viewer (the API's `can` plus the draft/approved state). */
export function availableActions(c: Certificate, readOnly = false): ActionKind[] {
  const a: ActionKind[] = []
  if (c.pdf_url || c.download_url) a.push('view')
  if (!readOnly) {
    if (c.can?.approve) a.push('approve')
    if (c.status === 'draft' && c.can?.update) a.push('edit', 'delete')
    if (c.status === 'approved' && c.can?.send) a.push('send')
  }
  if (c.download_url) a.push('download')
  if (c.print_url && !readOnly) a.push('print')
  if (readOnly && c.whatsapp_share_url) a.push('share')
  if (!readOnly && c.can?.revoke) a.push('revoke')
  return a
}

export const ACTION_ICON: Record<ActionKind, string> = {
  view: 'eye', approve: 'check', edit: 'edit', delete: 'trash', revoke: 'ban', send: 'messages', download: 'download', print: 'printer', share: 'share',
}

/** Opens a signed URL without leaving the page (the download link is an attachment). */
function followLink(url: string, newTab: boolean) {
  const a = document.createElement('a')
  a.href = url
  if (newTab) { a.target = '_blank'; a.rel = 'noopener noreferrer' }
  document.body.appendChild(a)
  a.click()
  a.remove()
}

/**
 * Shared action runner for the section list, the profile tab and the details drawer:
 * `run(kind, c)` performs the action or opens its dialog; render `element` once.
 */
export function useCertificateActions() {
  const { t, i18n } = useTranslation('certificates')
  const invalidate = useInvalidateCertificates()
  const [dialog, setDialog] = useState<{ kind: 'view' | 'edit' | 'revoke'; c: Certificate } | null>(null)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  const done = (text: string) => { setNotice({ tone: 'success', text }); setDialog(null) }
  const fail = (e: unknown) => setNotice({ tone: 'error', text: parseApiError(e).message })

  const approve = useMutation({ mutationFn: (c: Certificate) => certificatesApi.approve(c.id), onSuccess: (r) => { invalidate(); setDialog((d) => (d?.kind === 'view' ? { ...d, c: r.data } : d)); setNotice({ tone: 'success', text: r.message }) }, onError: fail, onSettled: () => setBusy(null) })
  const send = useMutation({ mutationFn: (c: Certificate) => certificatesApi.send(c.id), onSuccess: (r) => { invalidate(); setNotice(r.sent ? { tone: 'success', text: t('messages.sent') } : { tone: 'error', text: t('messages.not_sent') }) }, onError: fail, onSettled: () => setBusy(null) })
  const remove = useMutation({ mutationFn: (c: Certificate) => certificatesApi.remove(c.id), onSuccess: (r) => { invalidate(); done(r.message) }, onError: fail, onSettled: () => setBusy(null) })

  const run = (kind: ActionKind, c: Certificate) => {
    setNotice(null)
    switch (kind) {
      case 'view': case 'edit': case 'revoke':
        setDialog({ kind, c }); break
      case 'approve':
        if (window.confirm(t('confirm.approve', { no: c.certificate_no }))) { setBusy(`approve-${c.id}`); approve.mutate(c) }
        break
      case 'delete':
        if (window.confirm(t('confirm.delete', { no: c.certificate_no }))) { setBusy(`delete-${c.id}`); remove.mutate(c) }
        break
      case 'send':
        setBusy(`send-${c.id}`); send.mutate(c); break
      case 'download':
        if (c.download_url) followLink(c.download_url, false); break
      case 'print':
        if (c.print_url) { followLink(c.print_url, true); setTimeout(invalidate, 1500) } break
      case 'share':
        if (c.whatsapp_share_url) followLink(c.whatsapp_share_url, true); break
    }
  }

  const element = dialog && (
    dialog.kind === 'view' ? <CertificateDrawer c={dialog.c} onClose={() => setDialog(null)} run={run} busy={busy} notice={notice} locale={i18n.language} />
      : dialog.kind === 'edit' ? <EditDraftDialog c={dialog.c} onClose={() => setDialog(null)} onDone={done} />
        : <RevokeDialog c={dialog.c} onClose={() => setDialog(null)} onDone={done} />
  )

  return { run, element, notice, setNotice, busy }
}

/** Row of labelled action buttons. */
export function ActionButtons({ c, run, busy, readOnly = false, exclude = [], compact = false }: {
  c: Certificate; run: (k: ActionKind, c: Certificate) => void; busy?: string | null; readOnly?: boolean; exclude?: ActionKind[]; compact?: boolean
}) {
  const { t } = useTranslation('certificates')
  const label: Record<ActionKind, string> = {
    view: t('actions.view'), approve: t('actions.approve'), edit: t('actions.edit'), delete: t('actions.delete'), revoke: t('actions.revoke'),
    send: t('actions.resend_short'), download: t('actions.download'), print: t('actions.print'), share: t('actions.share'),
  }
  return (
    <div className="flex flex-wrap gap-1.5">
      {availableActions(c, readOnly).filter((k) => !exclude.includes(k)).map((k) => {
        const loading = busy === `${k}-${c.id}`
        const danger = k === 'revoke' || k === 'delete'
        return compact ? (
          <button key={k} type="button" onClick={() => run(k, c)} disabled={loading} title={label[k]} aria-label={`${label[k]} — ${c.certificate_no}`}
            className={`rounded-lg p-1.5 transition disabled:opacity-50 ${danger ? 'text-danger/80 hover:bg-danger/10' : k === 'approve' ? 'text-brand-700 hover:bg-brand-50' : 'text-ink/60 hover:bg-ink/5 hover:text-ink'}`}>
            {loading ? <StarSpinner className="size-4" /> : <Icon name={ACTION_ICON[k]} className="size-4" />}
          </button>
        ) : (
          <SecondaryButton key={k} onClick={() => run(k, c)} disabled={loading}
            className={`!px-2.5 !py-1.5 !text-xs ${danger ? '!text-danger hover:!bg-danger/5' : k === 'approve' ? '!border-brand-600/40 !text-brand-700' : ''}`}>
            {loading ? <StarSpinner className="size-3.5" /> : <Icon name={ACTION_ICON[k]} className="size-3.5" />}
            {label[k]}
          </SecondaryButton>
        )
      })}
    </div>
  )
}

/* ------------------------------------------------------------------ Details drawer */

function CertificateDrawer({ c: initial, onClose, run, busy, notice, locale }: {
  c: Certificate; onClose: () => void; run: (k: ActionKind, c: Certificate) => void; busy: string | null; notice: { tone: 'success' | 'error'; text: string } | null; locale: string
}) {
  const { t } = useTranslation('certificates')
  const { can } = useAuth()
  const titleId = useId()
  // Reload for the history fields (issued_by / approved_by) and fresh signed links.
  const full = useQuery({ queryKey: ['certificates', 'one', initial.id, locale], queryFn: () => certificatesApi.show(initial.id), enabled: !!initial.pdf_url, placeholderData: initial })
  const c = full.data ?? initial
  const readOnly = !c.can || (!c.can.approve && !c.can.update && !c.can.send && !c.can.revoke)
  // The signed inline view link feeds the iframe directly; without one, stream the authenticated PDF as a blob.
  const blob = useObjectUrl(c.pdf_url && !c.view_url ? () => certificatesApi.pdfObjectUrl(c.id) : null, [c.id, c.status, c.title, c.achievement, c.grade, c.issued_on, c.view_url])
  const pdf = c.view_url ? { url: c.view_url, loading: false, error: false } : blob

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const dt = (iso: string | null) => (iso ? formatDate(iso, locale, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—')

  return (
    <div className="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-labelledby={titleId}>
      <button type="button" tabIndex={-1} aria-hidden className="absolute inset-0 bg-ink/50" onClick={onClose} />
      <aside className="absolute inset-y-0 end-0 flex w-full max-w-4xl flex-col bg-page shadow-2xl">
        <header className="flex items-center justify-between gap-3 border-b border-ink/8 bg-white px-5 py-4">
          <div className="min-w-0">
            <h2 id={titleId} className="flex flex-wrap items-center gap-2 text-lg font-semibold text-ink">
              <span dir="auto">{displayTitle(c)}</span>
              <StatusBadge c={c} />
            </h2>
            <p className="text-sm tabular-nums text-ink/55">{c.certificate_no} · {c.type_label}</p>
          </div>
          <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-ink/50 hover:bg-ink/5 hover:text-ink" aria-label={t('actions.close')}>
            <Icon name="close" className="size-5" />
          </button>
        </header>

        <div className="flex-1 space-y-4 overflow-y-auto p-5">
          {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
          <ActionButtons c={c} run={run} busy={busy} readOnly={readOnly} exclude={['view']} />

          <div className="grid gap-4 lg:grid-cols-[1fr_18rem]">
            <section aria-label={t('detail.preview')} className="overflow-hidden rounded-2xl border border-ink/8 bg-white shadow-sm">
              {pdf.loading ? <div className="grid h-[28rem] place-items-center"><StarSpinner className="size-9 text-brand-600" /></div>
                : pdf.url ? <iframe title={t('detail.preview')} src={pdf.url} className="h-[28rem] w-full lg:h-[34rem]" />
                  : <p className="grid h-40 place-items-center text-sm text-ink/55">{t('detail.preview_error')}</p>}
            </section>

            <div className="space-y-4">
              <section className="rounded-2xl border border-ink/8 bg-white p-4 shadow-sm">
                <h3 className="mb-2 text-sm font-semibold text-ink">{t('detail.info')}</h3>
                <dl className="space-y-2 text-sm">
                  <Row label={t('detail.student')}>
                    <span dir="auto">{c.student?.full_name ?? '—'}</span>
                    {c.student && can('students.view') && (
                      <Link to={`/students/${c.student_id}?tab=certificates`} className="ms-1 text-xs text-brand-700 hover:underline" onClick={onClose}>{t('detail.open_profile')}</Link>
                    )}
                  </Row>
                  <Row label={t('detail.achievement')}><span dir="auto">{c.achievement}</span></Row>
                  <Row label={t('detail.grade')}>{c.grade_label ?? '—'}</Row>
                  <Row label={t('detail.issued_on')}><IssuedDate c={c} /></Row>
                  {c.lesson && <Row label={t('detail.lesson')}><span dir="auto">{c.lesson.name}</span></Row>}
                  <Row label={t('detail.source')}>{c.source_label}</Row>
                  <Row label={t('detail.serial')}><span className="font-mono tabular-nums">{c.certificate_no}</span></Row>
                  {c.verify_url && <Row label={t('detail.verify_link')}><a href={c.verify_url} target="_blank" rel="noreferrer" className="break-all text-xs text-brand-700 hover:underline" dir="ltr">{c.verify_url}</a></Row>}
                </dl>
              </section>

              <section className="rounded-2xl border border-ink/8 bg-white p-4 shadow-sm">
                <h3 className="mb-2 text-sm font-semibold text-ink">{t('detail.history')}</h3>
                <ol className="space-y-2.5 border-s-2 border-gold-400/50 ps-3 text-sm">
                  <HistoryItem label={t('detail.issued_by')} value={c.issued_by ?? t('detail.system')} when={c.issued_on ? formatDate(c.issued_on, locale, { day: 'numeric', month: 'short', year: 'numeric' }) : null} />
                  {c.approved_at && <HistoryItem label={t('detail.approved_by')} value={c.approved_by ?? t('detail.system')} when={dt(c.approved_at)} />}
                  <HistoryItem label={t('detail.sent_at')} value={c.sent_at ? dt(c.sent_at) : t('detail.not_sent')} />
                  <HistoryItem label={t('detail.print_count')} value={formatNumber(c.print_count, locale)} />
                  {c.revoked_at && <HistoryItem tone="danger" label={t('detail.revoked_at')} value={dt(c.revoked_at)} />}
                  {c.revoke_reason && <HistoryItem tone="danger" label={t('detail.revoke_reason')} value={c.revoke_reason} />}
                </ol>
              </section>
            </div>
          </div>
        </div>
      </aside>
    </div>
  )
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-ink/50">{label}</dt>
      <dd className="text-ink">{children}</dd>
    </div>
  )
}

function HistoryItem({ label, value, when, tone }: { label: string; value: string; when?: string | null; tone?: 'danger' }) {
  return (
    <li className="relative">
      <span className={`absolute -start-[1.19rem] top-1.5 size-2 rotate-45 ${tone === 'danger' ? 'bg-danger' : 'bg-gold-500'}`} aria-hidden />
      <p className="text-xs text-ink/50">{label}</p>
      <p dir="auto" className={tone === 'danger' ? 'text-danger' : 'text-ink'}>{value}{when && <span className="ms-1 text-xs text-ink/50">· {when}</span>}</p>
    </li>
  )
}
