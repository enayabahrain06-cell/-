import { api } from './client'

/** الدرجات (Phase 6): grade distribution, required lessons, exam score upload, gradebook, monitor, top students. */

export interface Ref { id: number; name: string }
export type ComponentKind = 'exam' | 'homework' | 'participation' | 'attendance' | 'project' | 'other'
export const COMPONENT_KINDS: ComponentKind[] = ['exam', 'homework', 'participation', 'attendance', 'project', 'other']

export interface GradeComponent {
  id: number
  level_subject_id: number
  name: string
  name_ar: string
  name_en: string | null
  kind: ComponentKind
  max_marks: number
  weight: number
  sort: number
  exam: { id: number; name: string; total_marks: number; status: string | null } | null
  entries?: number
}
export interface LevelSubjectRow { id: number; level: Ref; subject: Ref }
export interface WeightSummary { weight_total: number; warning: string | null }
export interface LinkableExam { id: number; name: string; type: string; total_marks: number; exam_date: string | null; where: string | null; component_id: number | null }
export interface DistributionData {
  term: Ref
  level_subjects: LevelSubjectRow[]
  level_subject?: LevelSubjectRow
  components?: GradeComponent[]
  summary?: WeightSummary
  exams?: LinkableExam[]
}
export interface ComponentInput { level_subject_id?: number; name_ar: string; name_en: string | null; kind: ComponentKind; max_marks: number | null; weight: number; exam_id: number | null }

export interface ExamLinkOptions {
  in_term: boolean
  subjects: Ref[]
  components: { id: number; name: string; weight: number; exam_id: number | null; subject: Ref; level: Ref }[]
  lessons: RequiredLesson[]
}
export interface RequiredLesson { id: number; title: string; description: string | null; level: Ref | null; subject_id?: number }
export interface RequiredLessonsData {
  exam: { id: number; name: string; type: string; syllabus: string | null; subject: Ref | null; where: string | null }
  selected: number[]
  available: RequiredLesson[]
  can_manage: boolean
}

export interface ScorePreviewRow { row: number; student_no: string | null; name: string | null; student_id: number | null; score: number | null; status: 'ok' | 'error' | 'empty'; errors: Record<string, string> }
export interface ScorePreview { rows: ScorePreviewRow[]; valid: number; invalid: number; empty: number }

export interface Cell { score: number | null; max: number; source: 'entry' | 'exam'; notes: string | null }
export interface BookRow { student: { id: number; student_no: string; full_name: string }; cells: Record<string, Cell>; total: number | null; complete: boolean }
export interface BookData {
  term: Ref
  classes: (Ref & { level_id: number | null; level: string | null })[]
  lesson?: Ref & { level: string | null }
  subjects?: (Ref & { level_subject_id: number })[]
  level_subject?: { id: number; subject: Ref } | null
  can_record?: boolean
  components?: GradeComponent[]
  weight_total?: number
  students?: BookRow[]
  averages?: { components: Record<string, number | null>; total: number | null }
}
export interface StudentGrades {
  student: { id: number; student_no: string; full_name: string }
  lesson: Ref & { level: string | null }
  term: Ref
  subjects: { subject: Ref; components: (GradeComponent & { cell: Cell | null })[]; total: number | null }[]
  average: number | null
}

export interface MonitorRow {
  lesson: Ref; level: Ref; subject: Ref
  component: { id: number; name: string; kind: ComponentKind } | null
  exam: { id: number; name: string; status: string | null } | null
  students: number; missing: number; exam_covers_class: boolean | null
  teachers: { id: number; name: string | null }[]
}
export interface MonitorData { term: Ref; levels: Ref[]; teachers: Ref[]; rows: MonitorRow[]; summary: { rows: number; incomplete: number; missing: number } }

export interface TopRow { rank: number; score: number; student: { id: number; student_no: string; full_name: string }; lesson: Ref; level: Ref | null; subjects: { subject: string; total: number }[] }
export interface TopData { term: Ref; levels: Ref[]; classes: (Ref & { level_id: number })[]; subjects: Ref[]; rows: TopRow[]; ranked: number }

type Params = Record<string, string | number | boolean | undefined>
const blob = async (url: string, params?: Params) => (await api.get(url, { params, responseType: 'blob' })).data as Blob

export const gradesApi = {
  distribution: (p: Params) => api.get<DistributionData>('/grade-components', { params: p }).then((r) => r.data),
  createComponent: (d: ComponentInput) => api.post<{ message: string; data: GradeComponent; summary: WeightSummary }>('/grade-components', d).then((r) => r.data),
  updateComponent: (id: number, d: Partial<ComponentInput>) => api.put<{ message: string; data: GradeComponent; summary: WeightSummary }>(`/grade-components/${id}`, d).then((r) => r.data),
  removeComponent: (id: number) => api.delete<{ message: string }>(`/grade-components/${id}`).then((r) => r.data),
  reorder: (levelSubjectId: number, ids: number[]) => api.post<{ message: string }>('/grade-components/reorder', { level_subject_id: levelSubjectId, ids }).then((r) => r.data),

  examOptions: (p: { lesson_id?: number | null; package_id?: number | null }) => api.get<ExamLinkOptions>('/grades/exam-options', { params: p }).then((r) => r.data),
  requiredLessons: (examId: number) => api.get<RequiredLessonsData>(`/exams/${examId}/required-lessons`).then((r) => r.data),
  saveRequiredLessons: (examId: number, ids: number[]) => api.put<{ message: string; selected: number[] }>(`/exams/${examId}/required-lessons`, { ids }).then((r) => r.data),
  scoresTemplate: (examId: number) => blob(`/exams/${examId}/scores-template.xlsx`),
  scoresPreview: (examId: number, file: File) => { const f = new FormData(); f.append('file', file); return api.post<ScorePreview>(`/exams/${examId}/scores-preview`, f).then((r) => r.data) },

  book: (p: Params) => api.get<BookData>('/grades/book', { params: p }).then((r) => r.data),
  saveBook: (d: { lesson_id: number; grade_component_id: number; entries: { student_id: number; score: number | null; notes?: string | null }[] }) =>
    api.put<BookData & { message: string; saved: number }>('/grades/book', d).then((r) => r.data),
  bookXlsx: (p: { lesson_id: number; subject_id?: number }) => blob('/grades/book.xlsx', p),
  student: (id: number, lessonId?: number) => api.get<StudentGrades>(`/grades/students/${id}`, { params: lessonId ? { lesson_id: lessonId } : {} }).then((r) => r.data),

  monitor: (p: Params) => api.get<MonitorData>('/grades/monitor', { params: p }).then((r) => r.data),
  topStudents: (p: Params) => api.get<TopData>('/honor/top-students', { params: p }).then((r) => r.data),
}
