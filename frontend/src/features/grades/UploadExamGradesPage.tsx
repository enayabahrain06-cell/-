import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { examsApi, type Exam } from '../../api/exams'
import { gradesApi, type ScorePreview } from '../../api/grades'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, LoadingState, Notice, PrimaryButton, Segmented, SURFACE, TABLE_HEAD, TableWrap } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'
import { PaperGrading } from '../exams/ExamDetailPage'
import { PreviewCounts, SheetPicker } from '../common/SheetImport'

type Mode = 'upload' | 'manual'

/**
 * رفع درجات الامتحان: a paper exam's scores from an Excel sheet (template → preview → commit) or typed by hand.
 * Both save through the exam's scores endpoint, so the exam's attempts stay the one place its scores live.
 */
export default function UploadExamGradesPage() {
  const { t, i18n } = useTranslation('grades')
  const locale = i18n.language
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const examId = Number(params.get('exam')) || ''
  const mode: Mode = params.get('mode') === 'manual' ? 'manual' : 'upload'
  const set = (k: string, v: string) => { const next = new URLSearchParams(params); if (v) next.set(k, v); else next.delete(k); setParams(next, { replace: true }) }
  const exams = useQuery({ queryKey: ['exams', 'paper-picker'], queryFn: () => examsApi.list({ type: 'paper', per_page: 200 }) })
  // A paper exam with a question paper is graded from each student's answers on the exam screen.
  const list = (exams.data?.data ?? []).filter((e) => (e.questions_count ?? 0) === 0)
  const exam = list.find((e) => e.id === examId)
  const [preview, setPreview] = useState<ScorePreview | null>(null)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const commit = useMutation({
    mutationFn: () => examsApi.scores(Number(examId), (preview?.rows ?? []).filter((r) => r.status === 'ok').map((r) => ({ student_id: r.student_id as number, score: r.score as number }))),
    onSuccess: () => {
      setNotice({ tone: 'success', text: t('upload.committed', { n: formatNumber(preview?.valid ?? 0, locale) }) })
      setPreview(null)
      void qc.invalidateQueries({ queryKey: ['exam-results', examId] })
      void qc.invalidateQueries({ queryKey: ['exam', examId] })
    },
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })

  return (
    <div className="space-y-5">
      <div className="hidden lg:block"><PageBand title={t('nav:menu.upload_exam_grades')} subtitle={t('upload.subtitle')} /></div>
      {exams.isLoading ? <LoadingState /> : exams.isError ? <ErrorState message={parseApiError(exams.error).message} onRetry={() => void exams.refetch()} /> : list.length === 0 ? (
        <EmptyCard icon="exams" title={t('upload.no_exams')} body={t('upload.no_exams_body')} />
      ) : (
        <>
          <FilterBar label={t('common.exam')}>
            <SelectField label={t('common.exam')} className="sm:w-96" value={String(examId)} onChange={(e) => { set('exam', e.target.value); setPreview(null); setNotice(null) }}
              options={[{ value: '', label: '—' }, ...list.map((e) => ({ value: String(e.id), label: t('common.exam_option', { name: e.name, where: e.lesson_name ?? e.package_name ?? '', date: formatDate(e.exam_date, locale, { day: 'numeric', month: 'short' }) }) }))]} />
            {exam && <p className="text-sm text-ink/60 sm:ms-auto">{t('upload.marks', { total: formatNumber(exam.total_marks, locale), pass: formatNumber(exam.pass_mark, locale) })}</p>}
          </FilterBar>
          {!exam ? <EmptyCard icon="exams" title={t('upload.pick')} /> : (
            <div className="space-y-4">
              <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
                <Segmented name="upload-mode" label={t('nav:menu.upload_exam_grades')} value={mode} onChange={(v) => set('mode', v === 'upload' ? '' : v)}
                  options={[{ value: 'upload', label: t('upload.mode_upload') }, { value: 'manual', label: t('upload.mode_manual') }]} />
              </div>
              {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
              {mode === 'manual' ? <PaperGrading exam={exam} onSaved={() => void qc.invalidateQueries({ queryKey: ['exam', examId] })} /> : (
                <UploadPanel exam={exam} preview={preview} onPreview={(p) => { setPreview(p); setNotice(null) }}
                  committing={commit.isPending} onCommit={() => commit.mutate()} />
              )}
              <Link to={`/exams/${exam.id}?tab=results`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
                {t('upload.open_results')}<Icon name="chevron" className="size-4 rtl:rotate-180" />
              </Link>
            </div>
          )}
        </>
      )}
    </div>
  )
}

function UploadPanel({ exam, preview, onPreview, committing, onCommit }: { exam: Exam; preview: ScorePreview | null; onPreview: (p: ScorePreview) => void; committing: boolean; onCommit: () => void }) {
  const { t, i18n } = useTranslation('grades')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-4">
      <div className={`${SURFACE} space-y-3 p-4`}>
        <p className="text-sm text-ink/70">{t('upload.hint')}</p>
        <SheetPicker template={() => gradesApi.scoresTemplate(exam.id)} templateName={`exam-${exam.id}-scores.xlsx`} onFile={async (f) => onPreview(await gradesApi.scoresPreview(exam.id, f))} />
      </div>
      {preview && (
        <div className="space-y-3">
          <PreviewCounts valid={preview.valid} invalid={preview.invalid}>
            {preview.empty > 0 && <Badge>{t('upload.empty', { n: n(preview.empty) })}</Badge>}
          </PreviewCounts>
          {preview.rows.length === 0 ? <EmptyCard icon="table" title={t('upload.no_rows')} /> : (
            <TableWrap surface>
              <table className="w-full min-w-[36rem] text-sm">
                <thead className={TABLE_HEAD}>
                  <tr>
                    <th className="px-4 py-3 text-start font-medium">{t('upload.row')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('common.student')}</th>
                    <th className="px-4 py-3 text-end font-medium">{t('common.score')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('upload.check')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-ink/6">
                  {preview.rows.map((r) => (
                    <tr key={r.row} className={r.status === 'error' ? 'bg-danger/5' : ''}>
                      <td className="px-4 py-3 tabular-nums text-ink/60">{n(r.row)}</td>
                      <td className="px-4 py-3"><span dir="auto" className="font-medium text-ink">{r.name ?? '—'}</span><span className="block text-xs tabular-nums text-ink/50"><bdi dir="ltr">{r.student_no}</bdi></span></td>
                      <td className="px-4 py-3 text-end tabular-nums">{r.score === null ? '—' : `${n(r.score)} / ${n(exam.total_marks)}`}</td>
                      <td className="px-4 py-3">
                        {r.status === 'ok' ? <Badge tone="brand">{t('upload.ok')}</Badge> : r.status === 'empty' ? <Badge>{t('upload.no_score')}</Badge> : (
                          <ul className="space-y-0.5 text-xs text-danger">{Object.values(r.errors).map((m) => <li key={m}>{m}</li>)}</ul>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </TableWrap>
          )}
          <div className="flex justify-end">
            <PrimaryButton loading={committing} disabled={preview.valid === 0} onClick={onCommit}><Icon name="check" className="size-4" />{t('upload.commit', { n: n(preview.valid) })}</PrimaryButton>
          </div>
        </div>
      )}
    </div>
  )
}
