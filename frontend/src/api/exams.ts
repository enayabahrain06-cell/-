import { api } from './client'
import type { StudentSummary } from './students'

export type ExamType = 'paper' | 'online' | 'placement'
export type ExamStatus = 'draft' | 'published' | 'closed' | 'graded'
export type QuestionType = 'mcq' | 'true_false' | 'complete_verse' | 'order_verses' | 'recitation'
export const QUESTION_TYPES: QuestionType[] = ['mcq', 'true_false', 'complete_verse', 'order_verses', 'recitation']

export interface Exam {
  id: number
  gender: string | null
  name: string
  type: ExamType
  type_label: string
  status: ExamStatus
  status_label: string
  package_id: number | null
  package_name?: string | null
  lesson_id: number | null
  lesson_name?: string | null
  exam_date: string
  opens_at: string
  closes_at: string
  is_open_now: boolean
  duration_minutes: number
  total_marks: number
  pass_mark: number
  syllabus: string | null
  randomize: boolean
  questions_count?: number
  attempts_count?: number
  questions?: Question[]
  results_sent_at: string | null
  /** Placement tests only: minimum percentage → recommended memorization level, highest first. */
  level_bands?: LevelBand[]
}

export interface LevelBand { min: number; level: string; level_label?: string | null }
export type QuestionDifficulty = 'easy' | 'medium' | 'hard'

export interface QuestionOption { key: string; text: string }
export interface Question {
  id: number
  exam_id: number
  type: QuestionType
  type_label: string
  prompt: string
  options: QuestionOption[] | null
  marks: number
  sort_order: number
  position?: number
  correct_answer?: { key?: string; value?: boolean; text?: string; alternatives?: string[]; order?: string[] } | null
  /** Optional labels (staff only). */
  category?: string | null
  difficulty?: QuestionDifficulty | null
}

export interface Answer {
  id: number
  question_id: number
  question?: Question
  answer: Record<string, unknown> | null
  saved_at: string | null
  audio_media_id: number | null
  audio_url: string | null
  score?: number | null
  is_correct?: boolean | null
  grader_note?: string | null
}

export interface Attempt {
  id: number
  exam_id: number
  student_id: number | null
  student?: StudentSummary
  status: 'in_progress' | 'submitted' | 'graded' | 'expired' | string
  status_label: string
  started_at: string | null
  expires_at: string | null
  submitted_at: string | null
  remaining_seconds?: number
  auto_score: number | null
  manual_score: number | null
  total_score: number | null
  passed: boolean | null
  graded_at: string | null
  sheet_media_id: number | null
  sheet_url: string | null
  answers?: Answer[]
  questions?: Question[]
}

/** For placement tests a row is one attempt: student_no holds the request number (if registered), full_name the candidate. */
export interface ResultRow {
  student_id: number | null; student_no: string | null; full_name: string; attempt_id: number | null; status: string | null; score: number | null; passed: boolean | null
  attempt_no?: number | null; percent?: number | null; recommended_level?: string | null; recommended_level_label?: string | null
}
export interface Results { rows: ResultRow[]; eligible: number; graded: number; passed: number; pass_rate: number; average: number; top: { student_id: number; full_name: string; score: number; passed: boolean }[] }
export interface ExamStats { eligible: number; graded: number; passed: number; pass_rate: number; average: number }

export interface ExamInput {
  name: string
  package_id: number | null
  lesson_id: number | null
  type: ExamType
  exam_date: string
  opens_at: string
  closes_at: string
  duration_minutes: number
  total_marks: number
  pass_mark: number
  syllabus: string | null
  randomize: boolean
  level_bands?: { min: number; level: string }[] | null
}

export interface QuestionInput {
  type: QuestionType
  prompt: string
  marks: number
  options?: QuestionOption[] | null
  correct_answer?: Question['correct_answer']
  category?: string | null
  difficulty?: QuestionDifficulty | null
}

/** One question on a paper answer sheet: the written answer (same shapes as online), or the recitation score. */
export interface PaperAnswerInput { question_id: number; answer?: { key?: string; value?: boolean; text?: string; order?: string[] } | null; score?: number | null }

export interface Paged<T> { data: T[]; meta: { current_page: number; last_page: number; total: number } }
export type MyExam = Exam & { attempt: Attempt | null }

export const examsApi = {
  list: (p: Record<string, string | number | undefined>) => api.get<Paged<Exam>>('/exams', { params: p }).then((r) => r.data),
  show: (id: number) => api.get<{ data: Exam; stats: ExamStats }>(`/exams/${id}`).then((r) => r.data),
  create: (d: ExamInput) => api.post<{ data: Exam }>('/exams', d).then((r) => r.data.data),
  update: (id: number, d: Partial<ExamInput>) => api.put<{ data: Exam }>(`/exams/${id}`, d).then((r) => r.data.data),
  remove: (id: number) => api.delete(`/exams/${id}`),
  publish: (id: number) => api.post(`/exams/${id}/publish`),
  close: (id: number) => api.post(`/exams/${id}/close`),
  questions: (id: number) => api.get<{ data: Question[] }>(`/exams/${id}/questions`).then((r) => r.data.data),
  addQuestion: (id: number, d: QuestionInput) => api.post(`/exams/${id}/questions`, d),
  updateQuestion: (id: number, qid: number, d: QuestionInput) => api.put(`/exams/${id}/questions/${qid}`, d),
  deleteQuestion: (id: number, qid: number) => api.delete(`/exams/${id}/questions/${qid}`),
  reorder: (id: number, ids: number[]) => api.post(`/exams/${id}/questions/reorder`, { ids }),
  attempts: (id: number) => api.get<{ data: Attempt[] }>(`/exams/${id}/attempts`, { params: { per_page: 500 } }).then((r) => r.data.data),
  attempt: (id: number, aid: number) => api.get<{ data: Attempt }>(`/exams/${id}/attempts/${aid}`).then((r) => r.data.data),
  grade: (id: number, aid: number, answers: { answer_id: number; score: number; grader_note?: string | null }[]) => api.put(`/exams/${id}/attempts/${aid}/grade`, { answers }),
  scores: (id: number, scores: { student_id: number; score: number; note?: string | null }[]) => api.put(`/exams/${id}/scores`, { scores }),
  uploadSheet: (id: number, aid: number, file: File) => { const f = new FormData(); f.append('sheet', file); return api.post(`/exams/${id}/attempts/${aid}/sheet`, f) },
  results: (id: number) => api.get<Results>(`/exams/${id}/results`).then((r) => r.data),
  sendResults: (id: number) => api.post<{ message: string; queued?: number }>(`/exams/${id}/results/send`).then((r) => r.data),
  certificates: (id: number) => api.post<{ message: string; data?: unknown[] }>(`/exams/${id}/certificates`).then((r) => r.data),
  resultsXlsx: async (id: number) => (await api.get(`/exams/${id}/results.xlsx`, { responseType: 'blob' })).data as Blob,
  rosterPdf: async (id: number) => (await api.get(`/exams/${id}/roster.pdf`, { responseType: 'blob' })).data as Blob,
  /** Paper exam with a question paper: one student's written answers; the server grades them and returns the attempt. */
  paperAnswers: (id: number, studentId: number, answers: PaperAnswerInput[]) =>
    api.put<{ data: Attempt }>(`/exams/${id}/students/${studentId}/answers`, { answers }).then((r) => r.data.data),
  questionPaperPdf: async (id: number) => (await api.get(`/exams/${id}/question-paper.pdf`, { responseType: 'blob' })).data as Blob,
  paperPdf: async (id: number, aid: number) => (await api.get(`/exams/${id}/attempts/${aid}/paper.pdf`, { responseType: 'blob' })).data as Blob,
  papersPdf: async (id: number) => (await api.get(`/exams/${id}/papers.pdf`, { responseType: 'blob' })).data as Blob,
}

export const myExamsApi = {
  list: (studentId?: number) => api.get<{ upcoming: MyExam[]; open: MyExam[]; finished: MyExam[] }>('/me/exams', { params: studentId ? { student_id: studentId } : {} }).then((r) => r.data),
  start: (examId: number, studentId?: number) => api.post<{ data: Attempt }>(`/me/exams/${examId}/start`, studentId ? { student_id: studentId } : {}).then((r) => r.data.data),
  attempt: (examId: number, studentId?: number) => api.get<{ data: Attempt }>(`/me/exams/${examId}/attempt`, { params: studentId ? { student_id: studentId } : {} }).then((r) => r.data.data),
  save: (examId: number, answers: { question_id: number; answer: unknown }[], studentId?: number) =>
    api.put<{ saved_at: string; remaining_seconds: number }>(`/me/exams/${examId}/attempt/answers`, { answers, ...(studentId ? { student_id: studentId } : {}) }).then((r) => r.data),
  audio: (examId: number, questionId: number, blob: Blob, studentId?: number) => {
    const f = new FormData()
    f.append('audio', blob, `recitation-${questionId}.${blob.type.includes('ogg') ? 'ogg' : blob.type.includes('mp4') ? 'm4a' : 'webm'}`)
    if (studentId) f.append('student_id', String(studentId))
    return api.post<{ answer_id: number; remaining_seconds: number }>(`/me/exams/${examId}/attempt/answers/${questionId}/audio`, f).then((r) => r.data)
  },
  submit: (examId: number, studentId?: number) => api.post<{ data: Attempt }>(`/me/exams/${examId}/attempt/submit`, studentId ? { student_id: studentId } : {}).then((r) => r.data.data),
}

/** Media files (audio, sheets) need the bearer token, so fetch them as blobs for <audio>/<img>. */
export async function fetchMediaUrl(url: string): Promise<string> {
  const r = await api.get(url.replace(/^.*\/api/, ''), { responseType: 'blob' })
  return URL.createObjectURL(r.data as Blob)
}
