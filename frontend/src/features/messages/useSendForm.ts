import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { messagesApi, type SendPayload } from '../../api/messages'
import type { StudentSummary } from '../../api/students'
import { useAuth } from '../../app/AuthContext'

export const MAX = 1000
type Mode = 'students' | 'phones'
type To = NonNullable<SendPayload['to']>

/** The send form's state and submit, shared by the desktop form and the mobile compose screen (MobileCompose). */
export function useSendForm() {
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

  return { canPhones, mode, setMode, students, setStudents, phones, setPhones, phoneList, to, setTo, lang, setLang, body, setBody, notice, setNotice, send, fieldError, submit }
}
export type SendForm = ReturnType<typeof useSendForm>
