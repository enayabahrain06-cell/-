import { api } from './client'
import type { StudentSummary } from './students'

type Paged<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number } }
type Params = Record<string, string | number | undefined>

// --- honor board (section 13) ------------------------------------------------------------------

export interface HonorRow {
  rank: number | null
  rank_in_track: number | null
  rank_in_package: number | null
  rank_in_circle: number | null
  points: number
  points_change: number | null
  breakdown: { attendance: number; evaluation: number; memorization: number; bonus: number }
  attendance_pct: number
  evaluation_avg: number
  new_ayahs: number
  lesson: { id: number; name: string } | null
  package: { id: number; name: string } | null
  student?: { id: number; full_name: string; initial: string; photo_url?: string | null }
}

export interface HonorBoard {
  id: number | null
  period: string
  gender: 'male' | 'female'
  status: 'none' | 'open' | 'finalized' | 'honored'
  published: boolean
  weights: { attendance: number; evaluation: number; memorization: number }
  honored_at: string | null
  level: 'track' | 'package' | 'circle'
  computed_at: string | null
  rows: HonorRow[]
  circle_of_month: { id: number; name: string; teacher: string | null; avg_points: number; students: number } | null
  totals: { students: number; badges: number }
}

export interface BadgeRow {
  id: number
  key: string
  name: string
  name_ar: string
  name_en: string
  description_ar: string | null
  description_en: string | null
  icon: string
  rule_type: string
  rule_value: number | null
  repeatable_monthly: boolean
  bonus_points: number
  is_active: boolean
  awarded: number
}

export interface EarnedBadge { id: number; badge: string; icon: string; key: string; period: string; awarded_at: string }

export const honorApi = {
  board: (p: Params) => api.get<{ data: HonorBoard; display_key: string | null }>('/honor/board', { params: p }).then((r) => r.data),
  periods: (gender?: string) => api.get<{ data: { id: number; period: string; status: string; published: boolean }[] }>('/honor/periods', { params: { gender } }).then((r) => r.data.data),
  compute: (period: string, gender?: string) => api.post<{ message: string; data: HonorBoard }>('/honor/compute', { period, gender }).then((r) => r.data),
  honor: (id: number, d: { certificates: boolean; messages: boolean; publish: boolean }) => api.post<{ message: string; data: { certificates: number; messages: number } }>(`/honor/periods/${id}/honor`, d).then((r) => r.data),
  publish: (id: number, published: boolean) => api.post<{ message: string }>(`/honor/periods/${id}/publish`, { published }).then((r) => r.data),
  badges: () => api.get<{ data: BadgeRow[] }>('/honor/badges').then((r) => r.data.data),
  updateBadge: (id: number, d: Partial<BadgeRow>) => api.put<{ data: BadgeRow }>(`/honor/badges/${id}`, d).then((r) => r.data.data),
  mine: (studentId?: number, period?: string) => api.get<{ data: { student: { id: number; full_name: string }; period: string; published: boolean; mine: HonorRow | null; board: HonorBoard | null; badges: EarnedBadge[] } }>('/my/honor', { params: { student_id: studentId, period } }).then((r) => r.data.data),
  display: (key: string, gender: string, locale: string) => api.get<{ data: { authority: { ar: string; en: string }; board: HonorBoard | null; badges: { student: string; badge: string; icon: string }[] } }>('/public/honor/display', { params: { key, gender }, headers: { 'Accept-Language': locale } }).then((r) => r.data.data),
}

// --- competitions (section 14) -----------------------------------------------------------------

export interface Criterion { key: string; name_ar: string; name_en?: string | null; weight: number; max: number }

export interface CompetitionRow {
  id: number
  name: string
  name_ar: string
  name_en: string | null
  gender: 'male' | 'female'
  type: 'memorization' | 'tajweed' | 'recitation' | 'knowledge'
  scope: 'circle' | 'package' | 'authority'
  status: 'draft' | 'open' | 'running' | 'judging' | 'finished' | 'cancelled'
  min_age: number | null
  max_age: number | null
  max_participants: number | null
  registration_opens_at: string
  registration_closes_at: string
  starts_at: string
  ends_at: string
  registration_open: boolean
  published: boolean
  participants_count: number | null
  rounds_count: number | null
  judges_count: number | null
}

export interface RoundRow { id: number; name: string; round_date: string; start_time: string | null; location_id: number | null; location: string | null; sort_order: number; status: string }

export interface CompetitionDetail extends CompetitionRow {
  description: string | null
  scope_lesson_id: number | null
  scope_package_id: number | null
  scope_lesson: { id: number; name: string } | null
  scope_package: { id: number; name: string } | null
  criteria: Criterion[]
  tie_break: 'last_round' | 'criterion_order' | 'age_younger' | 'registration_order'
  rounds: RoundRow[]
  prizes: { id: number; rank: number; title: string; badge_id: number | null; badge: string | null; points: number }[]
  judges: { id: number; user_id: number; name: string; round_id: number | null }[]
  can: { manage: boolean; judge: boolean }
}

export interface CompetitionInput {
  name_ar: string
  name_en?: string
  description?: string
  gender: 'male' | 'female'
  type: CompetitionRow['type']
  scope: CompetitionRow['scope']
  scope_lesson_id?: number | null
  scope_package_id?: number | null
  min_age?: number | null
  max_age?: number | null
  registration_opens_at: string
  registration_closes_at: string
  starts_at: string
  ends_at: string
  max_participants?: number | null
  tie_break: CompetitionDetail['tie_break']
  criteria: Criterion[]
  rounds: { id?: number; name: string; round_date: string; start_time?: string | null; location_id?: number | null }[]
  prizes: { rank: number; title: string; badge_id?: number | null; points?: number }[]
}

export interface ParticipantRow { id: number; status: string; registered_at: string; final_rank: number | null; final_score: number | null; student: StudentSummary | null }
export interface StandingRow { rank: number | null; participant_id: number; student: { id: number; full_name: string; initial: string }; rounds: Record<string, number>; final: number | null; judged: number }
export interface JudgingSheet {
  competition: CompetitionRow
  round: RoundRow
  criteria: Criterion[]
  locked: boolean
  participants: { id: number; student: { id: number; full_name: string; initial: string; photo_url: string | null }; scores: Record<string, number> | null; total: number | null; note: string | null }[]
}

export const competitionsApi = {
  list: (p: Params = {}) => api.get<Paged<CompetitionRow>>('/competitions', { params: p }).then((r) => r.data),
  defaults: () => api.get<{ data: { criteria: Criterion[] } }>('/competitions/defaults').then((r) => r.data.data),
  show: (id: number) => api.get<{ data: CompetitionDetail }>(`/competitions/${id}`).then((r) => r.data.data),
  create: (d: CompetitionInput) => api.post<{ data: CompetitionDetail }>('/competitions', d).then((r) => r.data.data),
  update: (id: number, d: CompetitionInput) => api.put<{ data: CompetitionDetail }>(`/competitions/${id}`, d).then((r) => r.data.data),
  remove: (id: number) => api.delete(`/competitions/${id}`),
  status: (id: number, status: string) => api.post<{ data: CompetitionDetail }>(`/competitions/${id}/status`, { status }).then((r) => r.data.data),
  participants: (id: number) => api.get<{ data: ParticipantRow[] }>(`/competitions/${id}/participants`).then((r) => r.data.data),
  candidates: (id: number, search?: string) => api.get<{ data: { student: StudentSummary; registered: boolean; eligible: boolean; reason: string | null }[] }>(`/competitions/${id}/candidates`, { params: { search } }).then((r) => r.data.data),
  register: (id: number, studentIds: number[]) => api.post<{ data: { registered: number; failed: { student_id: number; name: string; reason: string }[] } }>(`/competitions/${id}/participants`, { student_ids: studentIds }).then((r) => r.data.data),
  withdraw: (id: number, participantId: number) => api.delete(`/competitions/${id}/participants/${participantId}`),
  judgeCandidates: (id: number) => api.get<{ data: { id: number; name: string; roles: string[] }[] }>(`/competitions/${id}/judge-candidates`).then((r) => r.data.data),
  addJudge: (id: number, userId: number, roundId?: number | null) => api.post(`/competitions/${id}/judges`, { user_id: userId, round_id: roundId ?? null }),
  removeJudge: (id: number, judgeId: number) => api.delete(`/competitions/${id}/judges/${judgeId}`),
  sheet: (id: number, roundId: number) => api.get<{ data: JudgingSheet }>(`/competitions/${id}/rounds/${roundId}/sheet`).then((r) => r.data.data),
  score: (id: number, roundId: number, d: { participant_id: number; scores: Record<string, number>; note?: string }) => api.post<{ data: { total: number } }>(`/competitions/${id}/rounds/${roundId}/scores`, d).then((r) => r.data.data),
  standings: (id: number) => api.get<{ data: StandingRow[] }>(`/competitions/${id}/standings`).then((r) => r.data.data),
  publish: (id: number, notify: boolean) => api.post<{ data: { published: number; rewarded: number; notified: number } }>(`/competitions/${id}/publish`, { notify }).then((r) => r.data.data),
  mine: (studentId?: number) => api.get<{ data: { student: { id: number; full_name: string }; open: CompetitionRow[]; mine: (CompetitionRow & { participant_status: string; final_rank: number | null; final_score: number | null; rounds: RoundRow[] })[] } }>('/my/competitions', { params: { student_id: studentId } }).then((r) => r.data.data),
  registerSelf: (id: number, studentId?: number) => api.post(`/my/competitions/${id}/register`, { student_id: studentId }),
}

// --- challenges (section 14) -------------------------------------------------------------------

export type GoalType = 'memorize_range' | 'attendance_days' | 'revision_range' | 'score_streak' | 'points'

export interface ChallengeRow {
  id: number
  name: string
  name_ar: string
  name_en: string | null
  description: string | null
  gender: 'male' | 'female'
  scope: 'circle' | 'package' | 'authority'
  status: 'draft' | 'active' | 'finished' | 'cancelled'
  goal_type: GoalType
  goal_value: number
  surah_number: number | null
  surah_name: string | null
  from_ayah: number | null
  to_ayah: number | null
  min_score: number | null
  score_criterion: string | null
  starts_at: string
  ends_at: string
  days_left: number | null
  reward_points: number
  reward_badge_id: number | null
  reward_badge: string | null
  participants_count: number | null
  completed_count: number | null
}

export interface ChallengeProgress { participant_status: 'joined' | 'completed' | 'failed'; progress_value: number; progress_pct: number; completed_at: string | null; rewarded: boolean }

export interface ChallengeDetail extends ChallengeRow {
  scope_lesson_id: number | null
  scope_package_id: number | null
  min_age: number | null
  max_age: number | null
  leaderboard: (ChallengeProgress & { position: number; student: { id: number; full_name: string; initial: string; photo_url: string | null } | null })[]
}

export type ChallengeInput = Partial<Omit<ChallengeRow, 'id' | 'name' | 'surah_name' | 'days_left' | 'reward_badge' | 'participants_count' | 'completed_count'>> & { min_age?: number | null; max_age?: number | null; scope_lesson_id?: number | null; scope_package_id?: number | null }

export const challengesApi = {
  list: (p: Params = {}) => api.get<Paged<ChallengeRow>>('/challenges', { params: p }).then((r) => r.data),
  show: (id: number) => api.get<{ data: ChallengeDetail }>(`/challenges/${id}`).then((r) => r.data.data),
  create: (d: ChallengeInput) => api.post<{ data: ChallengeDetail }>('/challenges', d).then((r) => r.data.data),
  update: (id: number, d: ChallengeInput) => api.put<{ data: ChallengeDetail }>(`/challenges/${id}`, d).then((r) => r.data.data),
  remove: (id: number) => api.delete(`/challenges/${id}`),
  enroll: (id: number, studentIds: number[]) => api.post<{ data: { joined: number; failed: { student_id: number; name: string; reason: string }[] } }>(`/challenges/${id}/participants`, { student_ids: studentIds }).then((r) => r.data.data),
  refresh: (id: number) => api.post<{ data: ChallengeDetail }>(`/challenges/${id}/refresh`).then((r) => r.data.data),
  mine: (studentId?: number) => api.get<{ data: { student: { id: number; full_name: string }; open: ChallengeRow[]; mine: (ChallengeRow & ChallengeProgress)[] } }>('/my/challenges', { params: { student_id: studentId } }).then((r) => r.data.data),
  join: (id: number, studentId?: number) => api.post(`/my/challenges/${id}/join`, { student_id: studentId }),
}
