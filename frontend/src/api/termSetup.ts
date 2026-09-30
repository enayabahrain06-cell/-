import { api } from './client'

/** Term setup (اعدادات الفصل). Reads follow the top-bar term (?term_id=, added by the staff shell). */
export interface SetupTerm { id: number; name: string; start_date: string | null; end_date: string | null; is_current: boolean }
export type Ref = { id: number; name: string }

export interface SetupOptions {
  term: SetupTerm
  levels: Ref[]
  subjects: (Ref & { code: string | null })[]
  teachers: Ref[]
  supervisors: Ref[]
  halls: Ref[]
  circles: (Ref & { level_id: number })[]
  level_rooms: { level_id: number; location_id: number }[]
  weekdays: { value: string; label: string }[]
}

export interface LevelSubject {
  id: number
  academic_term_id: number
  level: Ref
  subject: Ref & { code: string | null }
  teacher: Ref | null
  weekly_sessions: number | null
  notes: string | null
  sort: number
  plan_items_count: number | null
}

export interface SubjectLesson { id: number; subject: Ref; level: Ref | null; title: string; description: string | null; sort: number; is_active: boolean }

export interface PlanItem { id: number; level_subject_id: number; week_no: number; subject_lesson_id: number | null; title: string | null; display_title: string; notes: string | null; sort: number }
export interface PlanWeek { week_no: number; starts_on: string | null }
export interface PlanView { term: SetupTerm; weeks: PlanWeek[]; subjects: (LevelSubject & { items: PlanItem[] })[] }

export interface NightSupervisor { id: number; weekday: string; weekday_label: string; user: (Ref & { phone: string }) | null; notes: string | null }

export interface TimetableSlot {
  id: number
  academic_term_id: number
  level: Ref
  lesson: Ref | null
  weekday: string
  weekday_label: string
  start_time: string
  end_time: string
  subject: Ref
  teacher: Ref | null
  location: Ref | null
  notes: string | null
}

type Saved<T> = { message: string; data: T }
type Termed<T> = { term: SetupTerm; data: T }

export interface LevelSubjectInput { academic_term_id: number; level_id: number; subject_id: number; teacher_id: number | null; weekly_sessions: number | null; notes: string | null; sort: number }
export interface SubjectLessonInput { subject_id: number; level_id: number | null; title: string; description: string | null; sort: number; is_active: boolean }
export interface PlanItemInput { level_subject_id: number; week_no: number; subject_lesson_id: number | null; title: string | null; notes: string | null }
export interface SlotInput { academic_term_id: number; level_id: number; lesson_id: number | null; weekday: string; start_time: string; end_time: string; subject_id: number; teacher_id: number | null; location_id: number | null; notes: string | null }
export type CopyPart = 'level_rooms' | 'level_subjects' | 'night_supervisors' | 'timetable'

export interface LevelRoomRow {
  level: Ref
  rooms: { location: Ref & { capacity: number }; level_room_id: number | null; sources: ('assigned' | 'circle' | 'timetable')[]; circles: string[] }[]
}

export const termSetupApi = {
  options: () => api.get<{ data: SetupOptions }>('/term-setup/options').then((r) => r.data.data),
  copy: (d: { from_term_id: number; to_term_id: number; parts: CopyPart[] }) => api.post<{ message: string }>('/term-setup/copy', d).then((r) => r.data),

  levelRooms: () => api.get<Termed<LevelRoomRow[]>>('/term-setup/level-rooms').then((r) => r.data),
  addLevelRoom: (d: { academic_term_id: number; level_id: number; location_id: number }) => api.post<{ message: string }>('/term-setup/level-rooms', d).then((r) => r.data),
  removeLevelRoom: (id: number) => api.delete<{ message: string }>(`/term-setup/level-rooms/${id}`).then((r) => r.data),

  levelSubjects: (p: { level_id?: number } = {}) => api.get<Termed<LevelSubject[]>>('/term-setup/level-subjects', { params: p }).then((r) => r.data),
  createLevelSubject: (d: LevelSubjectInput) => api.post<Saved<LevelSubject>>('/term-setup/level-subjects', d).then((r) => r.data),
  updateLevelSubject: (id: number, d: Partial<LevelSubjectInput>) => api.put<Saved<LevelSubject>>(`/term-setup/level-subjects/${id}`, d).then((r) => r.data),
  removeLevelSubject: (id: number) => api.delete<{ message: string }>(`/term-setup/level-subjects/${id}`).then((r) => r.data),

  subjectLessons: (p: { subject_id?: number; level_id?: number; active?: boolean } = {}) =>
    api.get<{ data: SubjectLesson[] }>('/term-setup/subject-lessons', { params: { ...p, active: p.active ? 1 : undefined } }).then((r) => r.data.data),
  createSubjectLesson: (d: SubjectLessonInput) => api.post<Saved<SubjectLesson>>('/term-setup/subject-lessons', d).then((r) => r.data),
  updateSubjectLesson: (id: number, d: SubjectLessonInput) => api.put<Saved<SubjectLesson>>(`/term-setup/subject-lessons/${id}`, d).then((r) => r.data),
  removeSubjectLesson: (id: number) => api.delete<{ message: string }>(`/term-setup/subject-lessons/${id}`).then((r) => r.data),

  plan: (levelSubjectId: number) => api.get<{ level_subject: LevelSubject; weeks: PlanWeek[]; data: PlanItem[] }>('/term-setup/plan', { params: { level_subject_id: levelSubjectId } }).then((r) => r.data),
  planView: (levelId: number) => api.get<PlanView>('/term-setup/plan/view', { params: { level_id: levelId } }).then((r) => r.data),
  createPlanItem: (d: PlanItemInput) => api.post<Saved<PlanItem>>('/term-setup/plan', d).then((r) => r.data),
  updatePlanItem: (id: number, d: Partial<PlanItemInput>) => api.put<Saved<PlanItem>>(`/term-setup/plan/${id}`, d).then((r) => r.data),
  removePlanItem: (id: number) => api.delete<{ message: string }>(`/term-setup/plan/${id}`).then((r) => r.data),

  supervisors: () => api.get<Termed<NightSupervisor[]>>('/term-setup/night-supervisors').then((r) => r.data),
  addSupervisor: (d: { academic_term_id: number; weekday: string; user_id: number }) => api.post<Saved<NightSupervisor>>('/term-setup/night-supervisors', d).then((r) => r.data),
  removeSupervisor: (id: number) => api.delete<{ message: string }>(`/term-setup/night-supervisors/${id}`).then((r) => r.data),

  timetable: (p: { level_id?: number; teacher_id?: number } = {}) => api.get<Termed<TimetableSlot[]>>('/term-setup/timetable', { params: p }).then((r) => r.data),
  createSlot: (d: SlotInput) => api.post<Saved<TimetableSlot> & { warnings: string[] }>('/term-setup/timetable', d).then((r) => r.data),
  updateSlot: (id: number, d: Partial<SlotInput>) => api.put<Saved<TimetableSlot> & { warnings: string[] }>(`/term-setup/timetable/${id}`, d).then((r) => r.data),
  removeSlot: (id: number) => api.delete<{ message: string }>(`/term-setup/timetable/${id}`).then((r) => r.data),
}
