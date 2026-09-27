import { api } from './client'

export type ReportFilter = 'period' | 'lesson' | 'package' | 'teacher' | 'gender' | 'min_absences' | 'months' | 'issue_status'

/** How a column's values render: numbers end-aligned and localised; percent and score keep their unit. */
export type ColumnType = 'text' | 'number' | 'percent' | 'score' | 'date' | 'datetime' | 'month' | 'phone'

export interface CatalogEntry {
  key: string
  group: string
  group_label: string
  icon: string
  title: string
  description: string
  /** Relative to /api. */
  endpoint: string
  filters: ReportFilter[]
  formats: ('xlsx' | 'pdf')[]
  chart: 'attendance_by_day' | null
}

export interface ReportCatalog {
  data: CatalogEntry[]
  groups: { key: string; label: string }[]
  options: {
    lessons: { id: number; name: string; package_id: number; teacher_id: number; active: boolean }[]
    packages: { id: number; name: string }[]
    teachers: { id: number; name: string }[]
    track: 'male' | 'female' | 'both'
  }
}

export type Cell = string | number | null

export interface ReportSection {
  key: string
  title: string
  headings: string[]
  /** Absent on older reports (finance): the viewer then guesses from the values. */
  types?: ColumnType[]
  rows: Cell[][]
}

export interface AttendanceDayPoint {
  date: string
  present: number
  late: number
  absent: number
  excused: number
  total: number
  rate: number | null
}

export interface Report {
  title: string
  period: string | null
  filters: Record<string, unknown>
  summary: [string, Cell][]
  sections: ReportSection[]
  data?: { by_day?: AttendanceDayPoint[] } & Record<string, unknown>
}

export type ReportParams = Partial<Record<'from' | 'to' | 'lesson_id' | 'package_id' | 'teacher_id' | 'gender' | 'min_absences' | 'months' | 'status', string>>

const clean = (p: ReportParams) => Object.fromEntries(Object.entries(p).filter(([, v]) => v !== undefined && v !== ''))

export const reportsApi = {
  catalog: () => api.get<ReportCatalog>('/reports').then((r) => r.data),
  get: (endpoint: string, params: ReportParams) => api.get<Report>(`/${endpoint}`, { params: clean(params) }).then((r) => r.data),
  export: async (endpoint: string, params: ReportParams, format: 'xlsx' | 'pdf') =>
    (await api.get(`/${endpoint}`, { params: { ...clean(params), format }, responseType: 'blob' })).data as Blob,
}
