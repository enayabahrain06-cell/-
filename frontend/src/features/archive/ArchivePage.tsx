import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { archiveApi, type ArchivePreview, type ArchiveRecord } from '../../api/archive'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, IconButton, LoadingState, Modal, Notice, PrimaryButton, SearchInput, Segmented, SecondaryButton, SURFACE, TABLE_HEAD, TableWrap, TextArea, TextInput } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'
import { useRemove } from '../common/crud'
import { PreviewCounts, SheetPicker } from '../common/SheetImport'
import { ArchiveTable } from './StudentArchiveTab'
import { useEmbed, useOwnParam } from '../../app/embed'

type Tab = 'upload' | 'view'

/** الأرشيف: رفع الأرشيف (Excel upload of previous years) and عرض الأرشيف (search and per-student history). */
export default function ArchivePage() {
  const { t } = useTranslation('archive')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const host = useEmbed()
  const ownTab = useOwnParam(params, 'tab')
  const tabs: Tab[] = [...(can('archive.manage') ? ['upload' as const] : []), 'view']
  const tab: Tab = ownTab === 'upload' && can('archive.manage') ? 'upload' : 'view'

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('title')} subtitle={t('subtitle')} />
      </div>
      {!host && tabs.length > 1 && (
        <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
          <Segmented name="archive-tab" label={t('title')} value={tab} onChange={(v) => setParams({ tab: v }, { replace: true })}
            options={[{ value: 'view', label: t('nav:menu.view_archive') }, { value: 'upload', label: t('nav:menu.upload_archive') }]} />
        </div>
      )}
      {tab === 'upload' ? <UploadTab /> : <ViewTab />}
    </div>
  )
}

function UploadTab() {
  const { t, i18n } = useTranslation('archive')
  const { t: tc } = useTranslation('common')
  const n = (v: number) => formatNumber(v, i18n.language)
  const qc = useQueryClient()
  const [preview, setPreview] = useState<ArchivePreview | null>(null)
  const [notes, setNotes] = useState('')
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const batches = useQuery({ queryKey: ['archive-batches'], queryFn: archiveApi.batches })
  const { notice: removed, remove } = useRemove(archiveApi.removeBatch, [['archive-batches'], ['archive-records'], ['student-archive']], t('batch_delete_confirm'))
  const commit = useMutation({
    mutationFn: () => archiveApi.commit({ file_name: preview!.file_name, notes: notes.trim() || null, rows: preview!.rows.filter((r) => !Object.keys(r.errors).length).map(({ row, data }) => ({ row, data })) }),
    onSuccess: (r) => {
      setNotice({ tone: 'success', text: r.message }); setPreview(null); setNotes('')
      ;['archive-batches', 'archive-records', 'student-archive'].forEach((k) => void qc.invalidateQueries({ queryKey: [k] }))
    },
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })

  return (
    <div className="space-y-4">
      <section className={`${SURFACE} space-y-3 p-4`}>
        <p className="text-sm text-ink/70">{t('upload_hint')}</p>
        <SheetPicker template={archiveApi.template} templateName="archive-template.xlsx" onFile={async (f) => { setNotice(null); setPreview(await archiveApi.preview(f)) }} />
      </section>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {removed && <Notice tone={removed.tone}>{removed.text}</Notice>}

      {preview && (
        <section className="space-y-3">
          <PreviewCounts valid={preview.valid} invalid={preview.invalid}>
            <Badge tone="info">{t('matched_count', { count: preview.matched, n: n(preview.matched) })}</Badge>
          </PreviewCounts>
          <TableWrap surface>
            <table className="w-full min-w-[44rem] text-sm">
              <thead className={TABLE_HEAD}>
                <tr>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{tc('sheet_import.row')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('col.full_name')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('col.academic_year')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('col.level_label')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('match')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{tc('sheet_import.status')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink/6">
                {preview.rows.map((r) => {
                  const errs = Object.values(r.errors)
                  return (
                    <tr key={r.row} className={errs.length ? 'bg-danger/5' : ''}>
                      <td className="px-3 py-2 tabular-nums text-ink/60">{n(r.row)}</td>
                      <td className="px-3 py-2"><span dir="auto" className="block font-medium text-ink">{r.data.full_name ?? ''}</span><span className="block text-xs tabular-nums text-ink/50" dir="ltr">{r.data.cpr ?? r.data.student_no ?? ''}</span></td>
                      <td className="px-3 py-2 tabular-nums" dir="ltr">{r.data.academic_year ?? ''}</td>
                      <td className="px-3 py-2 text-ink/70" dir="auto">{r.data.level_label ?? '—'}</td>
                      <td className="px-3 py-2">{r.match ? <><Badge tone="brand">{t(`match_by.${r.match.by}`)}</Badge><span dir="auto" className="mt-0.5 block text-xs text-ink/60">{r.match.full_name}</span></> : errs.length ? '—' : <Badge tone="gold">{t('unmatched')}</Badge>}</td>
                      <td className="px-3 py-2">{errs.length ? <span className="text-danger">{errs.join('، ')}</span> : <span className="text-brand-700">{tc('sheet_import.ok')}</span>}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </TableWrap>
          <div className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
            <TextArea label={t('notes')} rows={2} dir="auto" value={notes} onChange={(e) => setNotes(e.target.value)} />
            <div className="flex flex-wrap justify-end gap-2">
              <SecondaryButton onClick={() => setPreview(null)}>{t('cancel')}</SecondaryButton>
              <PrimaryButton disabled={preview.valid === 0} loading={commit.isPending} onClick={() => commit.mutate()}>{t('commit', { count: preview.valid, n: n(preview.valid) })}</PrimaryButton>
            </div>
          </div>
        </section>
      )}

      <section className="space-y-3">
        <h2 className="font-semibold text-ink">{t('batches')}</h2>
        {batches.isLoading ? <LoadingState /> : batches.isError ? <ErrorState onRetry={() => void batches.refetch()} /> : !batches.data?.length ? (
          <EmptyCard icon="download" title={t('no_batches')} />
        ) : (
          <ul className={`${SURFACE} divide-y divide-ink/6`}>
            {batches.data.map((b) => (
              <li key={b.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                <div className="min-w-0 flex-[1_1_12rem]">
                  <p dir="auto" className="truncate font-medium text-ink">{b.file_name}</p>
                  <p className="text-xs text-ink/55">
                    {t('batch_line', { rows: n(b.rows_count), matched: n(b.matched_count), count: b.rows_count })}
                    {b.created_at && <span className="ms-2">{formatDate(b.created_at, i18n.language, { day: 'numeric', month: 'short', year: 'numeric' })}</span>}
                    {b.uploaded_by && <span className="ms-2" dir="auto">{b.uploaded_by}</span>}
                  </p>
                  {b.notes && <p dir="auto" className="mt-0.5 text-xs text-ink/55">{b.notes}</p>}
                </div>
                <IconButton icon="trash" tone="danger" label={t('batch_delete')} onClick={() => remove(b.id)} />
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

function ViewTab() {
  const { t, i18n } = useTranslation('archive')
  const n = (v: number) => formatNumber(v, i18n.language)
  const [search, setSearch] = useState('')
  const [year, setYear] = useState('')
  const [level, setLevel] = useState('')
  const [page, setPage] = useState(1)
  const [history, setHistory] = useState<ArchiveRecord | null>(null)
  const q = useQuery({
    queryKey: ['archive-records', search, year, level, page],
    queryFn: () => archiveApi.records({ search: search.trim() || undefined, academic_year: year || undefined, level: level.trim() || undefined, page }),
    placeholderData: keepPreviousData,
  })
  const meta = q.data?.meta

  return (
    <div className="space-y-4">
      <FilterBar label={t('filters')}>
        <SearchInput label={t('search')} className="sm:w-72" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
        <SelectField label={t('col.academic_year')} hideLabel className="sm:w-48" value={year} onChange={(e) => { setYear(e.target.value); setPage(1) }}
          options={[{ value: '', label: t('all_years') }, ...(q.data?.years ?? []).map((y) => ({ value: y, label: y }))]} />
        <TextInput label={t('col.level_label')} hideLabel placeholder={t('level_filter')} className="sm:w-48" dir="auto" value={level} onChange={(e) => { setLevel(e.target.value); setPage(1) }} />
      </FilterBar>
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : (
        <>
          <p className="text-sm text-ink/60">{t('total', { count: meta?.total ?? 0, n: n(meta?.total ?? 0) })}</p>
          <ArchiveTable rows={q.data?.data ?? []} showName onName={setHistory} />
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-3">
              <SecondaryButton disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>{t('prev')}</SecondaryButton>
              <span className="text-sm tabular-nums text-ink/60">{t('page', { page: n(meta.current_page), last: n(meta.last_page) })}</span>
              <SecondaryButton disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>{t('next')}</SecondaryButton>
            </div>
          )}
        </>
      )}
      {history && <HistoryDialog record={history} onClose={() => setHistory(null)} />}
    </div>
  )
}

/** Every archive row of one student: the matched student, else rows with the same CPR or the same name. */
function HistoryDialog({ record, onClose }: { record: ArchiveRecord; onClose: () => void }) {
  const { t } = useTranslation('archive')
  const q = useQuery({
    queryKey: ['archive-records', 'history', record.id],
    queryFn: () => archiveApi.records(record.student ? { student_id: record.student.id } : { search: record.cpr ?? record.full_name }),
  })
  return (
    <Modal wide title={t('history_title', { name: record.student?.full_name ?? record.full_name })} onClose={onClose}>
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : <ArchiveTable rows={q.data?.data ?? []} />}
      {!record.student && <p className="text-xs text-ink/55">{t('history_unmatched')}</p>}
    </Modal>
  )
}
