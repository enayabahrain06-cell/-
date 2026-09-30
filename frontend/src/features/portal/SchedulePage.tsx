import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { portalApi, type ScheduleSession } from '../../api/portal'
import { MEmpty, MList, MListSkeleton, MSection, Pill } from '../../components/mobile/atoms'
import { formatTime } from '../../lib/format'
import PortalLayout from './PortalLayout'
import { ATTENDANCE_TONE, firstName, useChildFilter, usePortalOverview, usePortalRole } from './hooks'
import { ChildChips, DualDate, PortalError } from './shared'

const WEEK = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'] as const

/** The next two weeks of sessions and each circle's weekly days (/my-schedule). */
export default function SchedulePage() {
  const { t, i18n } = useTranslation('portal')
  const locale = i18n.language
  const role = usePortalRole()
  const overview = usePortalOverview()
  const cards = overview.data?.students ?? []
  const { selected, select } = useChildFilter(cards)
  const q = useQuery({ queryKey: ['portal-schedule', locale], queryFn: () => portalApi.schedule() })
  const sessions = (q.data?.sessions ?? []).filter((s) => selected === null || s.student_id === selected)
  const circles = (q.data?.circles ?? []).filter((c) => selected === null || c.student_id === selected)
  const many = cards.length > 1
  const byDate = sessions.reduce<Record<string, ScheduleSession[]>>((acc, s) => ((acc[s.date] ??= []).push(s), acc), {})
  const title = t('schedule.title')
  const time = (a: string, b: string) => `${formatTime(a, locale)} – ${formatTime(b, locale)}`

  const body = (
    <div className="space-y-6">
      <ChildChips cards={cards} selected={selected} onSelect={select} />

      {q.isError ? <PortalError onRetry={() => void q.refetch()} /> : q.isLoading ? <MListSkeleton rows={4} /> : (
        <>
          <MSection title={t('schedule.weekly')}>
            {circles.length === 0 ? <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty icon="lessons" text={t('schedule.no_circle')} /></div> : (
              <MList label={t('schedule.weekly')}>
                {circles.map((c) => (
                  <li key={`${c.student_id}-${c.id}`} className="px-4 py-3">
                    <p className="truncate text-[15px] font-semibold text-ink"><bdi>{c.name}</bdi>{many && <span className="font-normal text-ink/65"> · <bdi>{firstName(cards.find((x) => x.student.id === c.student_id)?.student.full_name ?? '')}</bdi></span>}</p>
                    <p className="mt-0.5 truncate text-[13px] tabular-nums text-ink/65">{time(c.start_time, c.end_time)}{c.location && <> · <bdi>{c.location}</bdi></>}{c.teacher && <> · <bdi>{c.teacher}</bdi></>}</p>
                    <ul className="mt-2 flex flex-wrap gap-1.5" aria-label={t('schedule.days')}>
                      {WEEK.map((d) => {
                        const on = c.days.includes(d)
                        return <li key={d} className={`inline-flex h-7 min-w-10 items-center justify-center rounded-full px-2 text-xs font-semibold ${on ? 'bg-brand-50 text-brand-700' : 'bg-ink/5 text-ink/65'}`}>
                          {t(`days.${d}`)}<span className="sr-only"> — {on ? t('schedule.day_on') : t('schedule.day_off')}</span>
                        </li>
                      })}
                    </ul>
                  </li>
                ))}
              </MList>
            )}
          </MSection>

          <MSection title={t('schedule.upcoming')}>
            {sessions.length === 0 ? <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty icon="clock" text={t('schedule.empty')} /></div> : (
              <div className="space-y-4">
                {Object.entries(byDate).map(([date, rows]) => (
                  <div key={date} className="space-y-2">
                    <h3 className="text-[13px] font-semibold text-ink/65"><DualDate value={date} /></h3>
                    <MList>
                      {rows.map((s) => (
                        <li key={`${s.student_id}-${s.id}`} className="flex min-h-16 items-center gap-3 px-4 py-2.5">
                          <span className="min-w-0 flex-1">
                            <span className="block truncate text-[15px] font-semibold tabular-nums text-ink">{time(s.start_time, s.end_time)}</span>
                            <span className="mt-0.5 block truncate text-[13px] text-ink/65">
                              {many && <><bdi>{firstName(s.student_name)}</bdi> · </>}<bdi>{s.lesson}</bdi>{s.location && <> · <bdi>{s.location}</bdi></>}
                            </span>
                          </span>
                          {s.status === 'cancelled' ? <Pill tone="err">{t('schedule.cancelled')}</Pill>
                            : s.attendance ? <Pill tone={ATTENDANCE_TONE[s.attendance]}>{t(`status.${s.attendance}`)}</Pill>
                            : s.location_changed ? <Pill tone="warn">{t('schedule.moved')}</Pill> : null}
                        </li>
                      ))}
                    </MList>
                  </div>
                ))}
              </div>
            )}
          </MSection>
        </>
      )}
    </div>
  )

  return role === 'student'
    ? <PortalLayout><h1 className="mb-4 font-display text-[22px] leading-7 text-brand-900 lg:text-3xl">{title}</h1>{body}</PortalLayout>
    : <PortalLayout title={title} back="/my-account" breadcrumb={[{ label: t('tabs.account'), to: '/my-account' }, { label: title }]}>{body}</PortalLayout>
}
