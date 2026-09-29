import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { enrollmentApi } from '../../api/enrollment'
import { lessonsApi } from '../../api/lessons'
import { parseApiError } from '../../api/client'
import type { StudentSummary } from '../../api/students'
import { Badge, Modal, Notice, PrimaryButton, SecondaryButton, LoadingState } from '../../components/ui'
import { formatMoney, formatNumber, formatTime } from '../../lib/format'

/**
 * Quick action for a student in no circle (e.g. saved from quick enrollment without a package):
 * the circles that fit their age and gender, best match first, and one click to place them.
 */
export default function AddToCircleDialog({ student, onClose }: { student: StudentSummary; onClose: () => void }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const queryClient = useQueryClient()
  const [lessonId, setLessonId] = useState<number | null>(null)

  const options = useQuery({
    queryKey: ['enrollment-options', student.birth_date, student.gender, locale],
    queryFn: () => enrollmentApi.options({ birth_date: student.birth_date ?? undefined, gender: student.gender }),
  })
  const packages = (options.data?.data ?? []).filter((p) => p.circles.length > 0)

  const save = useMutation({
    mutationFn: (id: number) => lessonsApi.enroll(id, [student.id]),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['students'] })
      onClose()
    },
  })

  return (
    <Modal
      title={t('add_circle.title', { name: student.full_name })}
      onClose={onClose}
      footer={
        <>
          <SecondaryButton type="button" onClick={onClose}>{t('add_circle.cancel')}</SecondaryButton>
          <PrimaryButton type="button" disabled={!lessonId} loading={save.isPending} onClick={() => lessonId && save.mutate(lessonId)}>
            {t('add_circle.save')}
          </PrimaryButton>
        </>
      }
    >
      {save.isError && <Notice tone="error">{parseApiError(save.error).message}</Notice>}
      {options.isLoading ? (
        <LoadingState />
      ) : options.isError ? (
        <Notice tone="error">{parseApiError(options.error).message}</Notice>
      ) : packages.length === 0 ? (
        <Notice tone="info">{t('add_circle.none')}</Notice>
      ) : (
        packages.map((p) => (
          <fieldset key={p.id} className="min-w-0 space-y-2">
            <legend className="mb-2 text-sm font-medium text-ink/75">
              <span dir="auto">{p.name}</span> <span className="font-normal text-ink/50">· {formatMoney(p.price_fils, locale)}</span>
            </legend>
            {p.circles.map((c) => (
              <label
                key={c.id}
                className={`block cursor-pointer rounded-xl border px-4 py-3 transition focus-within:ring-4 focus-within:ring-brand-100 ${
                  lessonId === c.id ? 'border-brand-600 bg-brand-50' : 'border-ink/15 bg-white hover:border-brand-500/50'
                }`}
              >
                <input type="radio" name="circle" value={c.id} checked={lessonId === c.id} onChange={() => setLessonId(c.id)} className="sr-only" />
                <span className="flex flex-wrap items-center gap-2">
                  <span dir="auto" className="font-medium text-ink">{c.name}</span>
                  {c.recommended && <Badge tone="brand">{t('add_circle.recommended')}</Badge>}
                </span>
                <span className="mt-0.5 block text-sm text-ink/60">
                  <span dir="auto">{c.teacher}</span> · <span dir="auto">{c.location}</span> · {formatTime(c.start_time, locale)}
                </span>
                <span className="mt-0.5 block text-xs text-brand-700">{t('add_circle.free_seats', { count: c.free_seats, n: formatNumber(c.free_seats, locale) })}</span>
              </label>
            ))}
          </fieldset>
        ))
      )}
    </Modal>
  )
}
