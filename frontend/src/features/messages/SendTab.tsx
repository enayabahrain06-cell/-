import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { messagesApi, type SendPayload } from '../../api/messages'
import type { StudentSummary } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import StudentPicker from '../../components/StudentPicker'
import { Card, CardTitle, Notice, PrimaryButton, Segmented, TextArea } from '../../components/ui'
import { formatNumber } from '../../lib/format'

const MAX = 1000
type Mode = 'students' | 'phones'
type To = NonNullable<SendPayload['to']>

export default function SendTab() {
  const { t, i18n } = useTranslation('messages')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const canPhones = can('messages.manage')
  const [mode, setMode] = useState<Mode>('students')
  const [students, setStudents] = useState<StudentSummary[]>([])
  const [phones, setPhones] = useState('')
  const [to, setTo] = useState<To>('guardian')
  const [lang, setLang] = useState<'ar' | 'en'>(locale === 'en' ? 'en' : 'ar')
  const [body, setBody] = useState('')
  const [fields, setFields] = useState<Record<string, string[]>>({})
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)

  const phoneList = phones.split(/[\n,،]+/).map((p) => p.trim()).filter(Boolean)
  const hasRecipients = mode === 'students' ? students.length > 0 : phoneList.length > 0

  const send = useMutation({
    mutationFn: () => messagesApi.send(mode === 'students'
      ? { student_ids: students.map((s) => s.id), to, body, locale: lang }
      : { phones: phoneList, body, locale: lang }),
    onSuccess: (r) => {
      setNotice({ tone: 'success', text: r.message })
      setFields({})
      setBody('')
      void qc.invalidateQueries({ queryKey: ['messages'] })
    },
    onError: (e) => {
      const err = parseApiError(e)
      setFields(err.fields)
      setNotice({ tone: 'error', text: err.message })
    },
  })

  const fieldError = (...keys: string[]) => {
    const hit = Object.entries(fields).find(([k]) => keys.some((key) => k === key || k.startsWith(`${key}.`)))
    return hit?.[1][0]
  }
  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    setNotice(null)
    if (!hasRecipients) { setNotice({ tone: 'error', text: t('send.need_recipient') }); return }
    send.mutate()
  }

  return (
    <form onSubmit={submit} className="grid gap-5 lg:grid-cols-5" noValidate>
      <Card className="space-y-4 lg:col-span-3">
        <CardTitle>{t('send.title')}</CardTitle>
        {!can('lessons.manage') && <Notice tone="info">{t('send.teacher_note')}</Notice>}

        {canPhones && (
          <Segmented name="send-mode" label={t('send.mode')} value={mode} onChange={setMode}
            options={[{ value: 'students', label: t('send.mode_students') }, { value: 'phones', label: t('send.mode_phones') }]} />
        )}

        {mode === 'students' ? (
          <div className="space-y-3">
            <StudentPicker label={t('send.add_student')} value={null}
              onChange={(s) => s && setStudents((cur) => (cur.some((x) => x.id === s.id) ? cur : [...cur, s]))} />
            {students.length === 0 ? (
              <p className="text-sm text-ink/50">{t('send.no_students')}</p>
            ) : (
              <ul className="flex flex-wrap gap-2" aria-label={t('send.students')}>
                {students.map((s) => (
                  <li key={s.id} className="inline-flex items-center gap-2 rounded-full border border-ink/10 bg-white py-1 pe-1 ps-1.5 text-sm shadow-sm">
                    <Avatar name={s.full_name} initial={s.initial} src={s.photo_url} gender={s.gender} size="sm" />
                    <span dir="auto" className="max-w-40 truncate">{s.full_name}</span>
                    <button type="button" onClick={() => setStudents((cur) => cur.filter((x) => x.id !== s.id))}
                      className="rounded-full p-1 text-ink/50 hover:bg-ink/5 hover:text-ink" aria-label={`${t('send.remove')} ${s.full_name}`}>
                      <Icon name="close" className="size-3.5" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
            {fieldError('student_ids') && <p className="text-sm text-danger">{fieldError('student_ids')}</p>}
            <div>
              <p className="mb-1.5 text-sm font-medium text-ink/75">{t('send.to')}</p>
              <Segmented name="send-to" label={t('send.to')} value={to} onChange={setTo} size="sm"
                options={(['guardian', 'student', 'both'] as const).map((v) => ({ value: v, label: t(`recipient.${v}`) }))} />
            </div>
          </div>
        ) : (
          <div>
            <TextArea label={t('send.phones')} rows={4} dir="ltr" value={phones} onChange={(e) => setPhones(e.target.value)}
              aria-invalid={!!fieldError('phones')} aria-describedby="phones-hint" placeholder="3600 0000" />
            <p id="phones-hint" className={`mt-1 text-xs ${fieldError('phones') ? 'text-danger' : 'text-ink/50'}`}>{fieldError('phones') ?? t('send.phones_hint')}</p>
          </div>
        )}

        <div>
          <p className="mb-1.5 text-sm font-medium text-ink/75">{t('send.language')}</p>
          <Segmented name="send-lang" label={t('send.language')} value={lang} onChange={setLang} size="sm"
            options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]} />
        </div>

        <div>
          <TextArea label={t('send.body')} rows={6} maxLength={MAX} dir={lang === 'ar' ? 'rtl' : 'ltr'} value={body} onChange={(e) => setBody(e.target.value)}
            aria-invalid={!!fieldError('body')} aria-describedby="body-count" required />
          <p id="body-count" className={`mt-1 flex justify-between gap-2 text-xs ${fieldError('body') ? 'text-danger' : 'text-ink/50'}`}>
            <span>{fieldError('body')}</span>
            <span className="tabular-nums">{t('send.chars', { n: formatNumber(body.length, locale), max: formatNumber(MAX, locale) })}</span>
          </p>
        </div>

        {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
        <div className="flex justify-end">
          <PrimaryButton type="submit" loading={send.isPending} disabled={!body.trim()}>
            <Icon name="messages" className="size-4" />
            {send.isPending ? t('send.sending') : t('send.submit')}
          </PrimaryButton>
        </div>
      </Card>

      <Card className="lg:col-span-2">
        <CardTitle>{t('send.preview')}</CardTitle>
        <div className="rounded-xl bg-page p-4">
          {body.trim() ? (
            <p dir={lang === 'ar' ? 'rtl' : 'ltr'} className="ms-auto max-w-[85%] whitespace-pre-wrap rounded-xl rounded-se-sm bg-brand-50 px-3 py-2 text-sm leading-relaxed text-ink shadow-sm">
              {body}
            </p>
          ) : (
            <p className="text-center text-sm text-ink/45">{t('send.preview_empty')}</p>
          )}
        </div>
      </Card>
    </form>
  )
}
