import { useId, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { messagesApi } from '../../api/messages'
import { studentsApi, type StudentSummary } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { StickyActionBar } from '../../components/mobile/ActionBars'
import MobileToast from '../../components/mobile/Toast'
import { MAvatar, MCard, MEmpty, MList, MListSkeleton, MRow, MSection, MSegmented, Pill, M_BTN_PRIMARY } from '../../components/mobile/atoms'
import { formatNumber } from '../../lib/format'
import { formatDateTime } from './status'
import { statusTone } from './mobileStatus'
import { MAX, type SendForm } from './useSendForm'

/**
 * Compose screen below lg (mobile-redesign-spec.md §6.22), the messages "send" tab. State, validation and the send
 * call come from useSendForm, the same hook the desktop form uses.
 */
export default function MobileCompose({ f }: { f: SendForm }) {
  const { t, i18n } = useTranslation('messages')
  const locale = i18n.language
  const { can } = useAuth()
  const n = (v: number) => formatNumber(v, locale)
  const bodyId = useId()
  // The toast hides itself after 4s without clearing the shared notice (the desktop form still shows it).
  const [dismissed, setDismissed] = useState<object | null>(null)
  const toast = f.notice && f.notice !== dismissed ? f.notice : null

  const count = f.mode === 'students' ? f.students.length : f.phoneList.length
  const who = f.mode === 'phones' ? 'phones' : f.to
  const bodyError = f.fieldError('body')

  return (
    <form onSubmit={f.submit} noValidate className="space-y-5 lg:hidden">
      <MobileToast message={toast?.text ?? null} tone={toast?.tone === 'error' ? 'error' : 'ok'} onDone={() => setDismissed(f.notice)} />

      <MCard className="space-y-4">
        {!can('lessons.manage') && <p className="rounded-ctl bg-info/10 px-3 py-2 text-[13px] text-info">{t('send.teacher_note')}</p>}
        {f.canPhones && (
          <MSegmented label={t('send.mode')} value={f.mode} onChange={f.setMode}
            options={[{ value: 'students', label: t('send.mode_students') }, { value: 'phones', label: t('send.mode_phones') }]} />
        )}

        {f.mode === 'students' ? (
          <div className="space-y-3">
            <p className="text-xs font-semibold text-ink/65">{t('mobile.recipients')}{f.students.length > 0 && <span className="tabular-nums"> ({n(f.students.length)})</span>}</p>
            {f.students.length > 0 && (
              <ul className="flex flex-wrap gap-2" aria-label={t('send.students')}>
                {f.students.map((s) => (
                  <li key={s.id} className="inline-flex max-w-full items-center gap-1.5 rounded-full border border-ink/10 bg-brand-50 ps-3 text-[13px] font-medium text-brand-700">
                    <bdi className="min-w-0 truncate">{s.full_name}</bdi>
                    <button type="button" onClick={() => f.setStudents((cur) => cur.filter((x) => x.id !== s.id))}
                      className="inline-grid size-11 shrink-0 place-items-center rounded-full -my-1.5" aria-label={`${t('send.remove')} ${s.full_name}`}>
                      <Icon name="close" className="size-4" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
            <StudentSearch onPick={(s) => f.setStudents((cur) => (cur.some((x) => x.id === s.id) ? cur : [...cur, s]))} />
            {f.fieldError('student_ids') && <p className="text-[13px] text-danger">{f.fieldError('student_ids')}</p>}
            <div className="space-y-1.5">
              <p className="text-xs font-semibold text-ink/65">{t('send.to')}</p>
              <MSegmented label={t('send.to')} value={f.to} onChange={f.setTo}
                options={(['guardian', 'student', 'both'] as const).map((v) => ({ value: v, label: t(`mobile.to.${v}`) }))} />
            </div>
          </div>
        ) : (
          <label className="block">
            <span className="mb-1.5 block text-[13px] font-medium text-ink/75">{t('send.phones')}</span>
            <textarea rows={4} dir="ltr" inputMode="tel" value={f.phones} onChange={(e) => f.setPhones(e.target.value)} placeholder="3600 0000"
              aria-invalid={!!f.fieldError('phones')}
              className="w-full rounded-md border border-ink/10 bg-white px-3.5 py-3 text-[15px] text-ink placeholder:text-ink/65 focus:outline-2 focus:outline-offset-2 focus:outline-brand-500" />
            <span className={`mt-1 block text-[13px] ${f.fieldError('phones') ? 'text-danger' : 'text-ink/65'}`}>{f.fieldError('phones') ?? t('send.phones_hint')}</span>
          </label>
        )}

        <div className="space-y-1.5">
          <p className="text-xs font-semibold text-ink/65">{t('send.language')}</p>
          <MSegmented label={t('send.language')} value={f.lang} onChange={f.setLang} options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]} />
        </div>

        <div>
          <label htmlFor={bodyId} className="mb-1.5 block text-[13px] font-medium text-ink/75">{t('send.body')}</label>
          <textarea id={bodyId} rows={5} maxLength={MAX} dir={f.lang === 'ar' ? 'rtl' : 'ltr'} value={f.body} onChange={(e) => f.setBody(e.target.value)}
            aria-invalid={!!bodyError} aria-describedby={`${bodyId}-count`} required
            className={`w-full rounded-md border bg-white px-3.5 py-3 text-[15px] leading-6 text-ink focus:outline-2 focus:outline-offset-2 focus:outline-brand-500 ${bodyError ? 'border-danger/60' : 'border-ink/10'}`} />
          <p id={`${bodyId}-count`} className={`mt-1 flex justify-between gap-2 text-xs ${bodyError ? 'text-danger' : 'text-ink/65'}`}>
            <span>{bodyError}</span>
            <span className="tabular-nums">{t('send.chars', { n: n(f.body.length), max: n(MAX) })}</span>
          </p>
        </div>
      </MCard>

      {can('messages.view') && <Recent />}

      <StickyActionBar>
        <button type="submit" disabled={!f.body.trim() || f.send.isPending} aria-busy={f.send.isPending} className={`${M_BTN_PRIMARY} min-w-0 flex-1`}>
          <Icon name="messages" className="size-5 shrink-0" />
          <span className="truncate">{f.send.isPending ? t('send.sending') : count > 0 ? t(`mobile.send_${who}`, { count, n: n(count) }) : t('send.submit')}</span>
        </button>
      </StickyActionBar>
    </form>
  )
}

/** Mobile student search: 48px input, 52px result rows; adds to the recipient tags. */
function StudentSearch({ onPick }: { onPick: (s: StudentSummary) => void }) {
  const { t } = useTranslation('messages')
  const id = useId()
  const [q, setQ] = useState('')
  const term = q.trim()
  // Same query key as components/StudentPicker, so both pickers share results.
  const results = useQuery({ queryKey: ['student-picker', q, undefined], queryFn: () => studentsApi.list({ search: q, gender: undefined, per_page: 8 }), enabled: term.length >= 2 })
  return (
    <div>
      <label htmlFor={id} className="sr-only">{t('send.add_student')}</label>
      <div className="flex h-12 items-center gap-2 rounded-md border border-ink/10 bg-white px-3 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-500">
        <Icon name="plus" className="size-5 shrink-0 text-ink/65" />
        <input id={id} type="search" autoComplete="off" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('mobile.add_placeholder')}
          className="min-w-0 flex-1 bg-transparent text-[15px] text-ink placeholder:text-ink/65 focus:outline-none" />
      </div>
      {term.length >= 2 && results.data && (
        <ul role="listbox" aria-label={t('send.add_student')} className="mt-2 divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white">
          {results.data.data.length === 0 ? <li className="px-4 py-3 text-[15px] text-ink/65">{t('common:picker.none')}</li> : results.data.data.map((s) => (
            <li key={s.id}>
              <button type="button" role="option" aria-selected={false} onClick={() => { onPick(s); setQ('') }} className="flex min-h-[52px] w-full items-center gap-3 px-4 py-2 text-start">
                <MAvatar name={s.full_name} src={s.photo_url} size={36} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[15px] text-ink"><bdi>{s.full_name}</bdi></span>
                  <span className="block truncate text-[13px] text-ink/65"><span dir="ltr" className="tabular-nums">{s.student_no} · {s.guardian_phone}</span></span>
                </span>
                <Icon name="plus" className="size-5 shrink-0 text-brand-700" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/** Last five messages; the full log is the log tab. */
function Recent() {
  const { t, i18n } = useTranslation('messages')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['messages', 'logs', 'recent'], queryFn: () => messagesApi.logs({ page: 1, per_page: 5 }) })
  return (
    <MSection title={t('mobile.recent')} action={{ label: t('mobile.all'), to: '/messages?tab=log' }}>
      {q.isLoading ? <MListSkeleton rows={3} /> : !q.data?.data.length ? <MCard><MEmpty icon="messages" text={t('log.empty')} /></MCard> : (
        <MList label={t('mobile.recent')}>
          {q.data.data.map((m) => (
            <MRow key={m.id} title={m.student_name || t('log.no_name')}
              caption={<>{t(`types.${m.type}`, { defaultValue: m.type_label ?? m.type })}{m.created_at && <> · <span className="tabular-nums">{formatDateTime(m.created_at, locale)}</span></>}</>}
              trailing={<Pill tone={statusTone(m.status)}>{t(`status.${m.status}`, { defaultValue: m.status })}</Pill>} />
          ))}
        </MList>
      )}
    </MSection>
  )
}
