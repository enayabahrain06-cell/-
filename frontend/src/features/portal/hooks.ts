import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useParams, useSearchParams } from 'react-router-dom'
import type { AttendanceStatus } from '../../api/attendance'
import { portalApi, type PortalCard } from '../../api/portal'
import { useAuth } from '../../app/AuthContext'
import type { PillTone } from '../../components/mobile/atoms'
import { formatNumber, formatTime, formatWeekday } from '../../lib/format'

/** Guardian (home: my children) or student (home: my progress). A login holding both roles is treated as a guardian. */
export function usePortalRole(): 'guardian' | 'student' {
  const { hasRole } = useAuth()
  return hasRole('student') && !hasRole('guardian') ? 'student' : 'guardian'
}

/** Bottom-nav tabs per portal role (mobile-redesign-spec.md §4.3): guardian 4 tabs, student 4 tabs. */
export function usePortalTabs() {
  const role = usePortalRole()
  return role === 'student'
    ? [
        { key: 'progress', to: '/my-progress', icon: 'chart' },
        { key: 'schedule', to: '/my-schedule', icon: 'clock' },
        { key: 'challenges', to: '/my/honor', icon: 'trophy' },
        { key: 'account', to: '/my-account', icon: 'users' },
      ]
    : [
        { key: 'children', to: '/my-children', icon: 'students' },
        { key: 'invoices', to: '/my-invoices', icon: 'wallet' },
        { key: 'messages', to: '/my-messages', icon: 'messages' },
        { key: 'account', to: '/my-account', icon: 'users' },
      ]
}

/** The family overview (one card per student), shared by every portal page through the query cache. */
export function usePortalOverview() {
  const { i18n } = useTranslation()
  return useQuery({ queryKey: ['portal-overview', i18n.language], queryFn: portalApi.overview, staleTime: 60_000 })
}

/**
 * The student a child page is about: /my-children/:id/… names it; the student's own pages (/my-progress/…) use the
 * signed-in student's own card. `card` is undefined while loading or when the id is not in the family.
 */
export function useFamilyStudent() {
  const { id } = useParams()
  const q = usePortalOverview()
  const cards = q.data?.students ?? []
  const card = id ? cards.find((c) => c.student.id === Number(id)) : cards[0]
  return { card, cards, loading: q.isLoading, error: q.isError, refetch: () => void q.refetch(), base: id ? `/my-children/${id}` : '/my-progress', home: id ? '/my-children' : '/my-progress' }
}

/** Child filter for family-wide pages (invoices, schedule): ?student=<id> in the URL, all children when absent. */
export function useChildFilter(cards: PortalCard[]) {
  const [params, setParams] = useSearchParams()
  const raw = params.get('student')
  const selected = raw && cards.some((c) => c.student.id === Number(raw)) ? Number(raw) : null
  const select = (id: number | null) => {
    const next = new URLSearchParams(params)
    if (id === null) next.delete('student')
    else next.set('student', String(id))
    setParams(next, { replace: true })
  }
  return { selected, select }
}

export const firstName = (full: string) => full.trim().split(/\s+/)[0] ?? full

export const ATTENDANCE_TONE: Record<AttendanceStatus, PillTone> = { present: 'ok', late: 'warn', absent: 'err', excused: 'info' }

/** Today pill: the child's mark in today's session, today's session time, or the next session day. */
export function useTodayPill(card: PortalCard): { tone: PillTone; label: string } {
  const { t, i18n } = useTranslation('portal')
  const locale = i18n.language
  const g = card.student.gender === 'female' ? 'f' : 'm'
  if (card.today?.status === 'cancelled') return { tone: 'neutral', label: t('today.cancelled') }
  if (card.today?.attendance) return { tone: ATTENDANCE_TONE[card.today.attendance], label: t(`today.${card.today.attendance}_${g}`) }
  if (card.today) return { tone: 'info', label: t('today.at', { time: formatTime(card.today.start_time, locale) }) }
  if (card.next_session) return { tone: 'neutral', label: t('today.next', { day: formatWeekday(card.next_session.date, locale) }) }
  return { tone: 'neutral', label: t('today.none') }
}

export const num = (v: number, locale: string, digits = 0) => formatNumber(v, locale, { maximumFractionDigits: digits })
