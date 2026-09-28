import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { enrollmentApi, type ImportPreview, type ImportResult } from '../../api/enrollment'
import { parseApiError } from '../../api/client'
import Alert from '../../components/Alert'
import Button from '../../components/Button'
import { formatNumber } from '../../lib/format'
import { SURFACE, TABLE_HEAD, buttonClass } from '../../components/ui'

/** Upload an Excel sheet, review every row (errors in red, possible duplicates in gold), then enroll the valid rows. */
export default function BulkImport() {
  const { t, i18n } = useTranslation('enrollment')
  const locale = i18n.language
  const fileRef = useRef<HTMLInputElement>(null)
  const [preview, setPreview] = useState<ImportPreview | null>(null)
  const [result, setResult] = useState<ImportResult | null>(null)
  const [includeWarnings, setIncludeWarnings] = useState(false)
  const [busy, setBusy] = useState<'preview' | 'commit' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const downloadTemplate = async () => {
    try {
      const blob = await enrollmentApi.template()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = 'quick-enrollment-template.xlsx'
      a.click()
      URL.revokeObjectURL(url)
    } catch (e) {
      setError(parseApiError(e).message)
    }
  }

  const onFile = async (file: File | undefined) => {
    if (!file) return
    setBusy('preview')
    setError(null)
    setResult(null)
    try {
      setPreview(await enrollmentApi.preview(file))
    } catch (e) {
      const { message, fields } = parseApiError(e)
      setError(fields.file?.[0] ?? message)
    } finally {
      setBusy(null)
      if (fileRef.current) fileRef.current.value = ''
    }
  }

  const commit = async () => {
    if (!preview) return
    setBusy('commit')
    setError(null)
    try {
      const rows = preview.rows.filter((r) => !Object.keys(r.errors).length && (includeWarnings || !r.warnings.length))
      setResult(await enrollmentApi.commit(rows, includeWarnings))
      setPreview(null)
    } catch (e) {
      setError(parseApiError(e).message)
    } finally {
      setBusy(null)
    }
  }

  const toEnroll = preview ? preview.valid + (includeWarnings ? preview.warnings : 0) : 0

  return (
    <div className="space-y-5">
      <div className={`${SURFACE} p-4 sm:p-5`}>
        <p className="text-sm text-ink/70">{t('import_intro')}</p>
        <div className="mt-4 flex flex-wrap gap-3">
          <button type="button" onClick={() => void downloadTemplate()} className={buttonClass('secondary')}>
            {t('download_template')}
          </button>
          <input ref={fileRef} type="file" accept=".xlsx,.xls,.csv" className="sr-only" id="import-file" onChange={(e) => void onFile(e.target.files?.[0])} />
          <label htmlFor="import-file" className="cursor-pointer rounded-lg bg-brand-700 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-800 focus-within:outline-2">
            {busy === 'preview' ? t('reading') : t('choose_file')}
          </label>
        </div>
      </div>

      {error && <Alert>{error}</Alert>}
      {result && (
        <Alert tone="success">
          <p className="font-medium">{result.message}</p>
          {result.skipped.length > 0 && <p className="mt-1">{t('skipped_rows', { rows: result.skipped.map((r) => formatNumber(r.row, locale)).join('، ') })}</p>}
        </Alert>
      )}

      {preview && (
        <section className={SURFACE} aria-labelledby="preview-title">
          <div className="flex flex-wrap items-center gap-3 border-b border-ink/8 px-5 py-4">
            <h2 id="preview-title" className="font-semibold text-ink">{t('preview')}</h2>
            <span className="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-700">{t('count_valid', { count: preview.valid })}</span>
            <span className="rounded-full bg-gold-500/12 px-2.5 py-0.5 text-xs font-medium text-gold-700">{t('count_warnings', { count: preview.warnings })}</span>
            <span className="rounded-full bg-danger/10 px-2.5 py-0.5 text-xs font-medium text-danger">{t('count_invalid', { count: preview.invalid })}</span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[40rem] text-sm">
              <thead className={TABLE_HEAD}>
                <tr>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('col_row')}</th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('full_name')}</th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('guardian_phone')}</th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('circle')}</th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('col_status')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink/6">
                {preview.rows.map((r) => {
                  const errs = Object.values(r.errors)
                  const tone = errs.length ? 'bg-danger/5' : r.warnings.length ? 'bg-gold-500/6' : ''
                  return (
                    <tr key={r.row} className={tone}>
                      <td className="px-4 py-3 tabular-nums text-ink/60">{formatNumber(r.row, locale)}</td>
                      <td className="px-4 py-3 font-medium text-ink" dir="auto">{String(r.data.full_name ?? '')}</td>
                      <td className="px-4 py-3 text-ink/70" dir="ltr">{String(r.data.guardian_phone ?? '')}</td>
                      <td className="px-4 py-3 tabular-nums text-ink/70">{String(r.data.circle_id ?? '')}</td>
                      <td className="px-4 py-3">
                        {errs.length ? (
                          <span className="text-danger">{errs.join(' · ')}</span>
                        ) : r.warnings.length ? (
                          <span className="text-gold-700">{r.warnings.join(' · ')}</span>
                        ) : (
                          <span className="text-brand-700">{t('row_ok')}</span>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          <div className="flex flex-wrap items-center gap-4 border-t border-ink/8 px-5 py-4">
            {preview.warnings > 0 && (
              <label className="flex items-center gap-2 text-sm text-ink/75">
                <input type="checkbox" checked={includeWarnings} onChange={(e) => setIncludeWarnings(e.target.checked)} className="size-4 accent-brand-700" />
                {t('include_warnings')}
              </label>
            )}
            <Button type="button" className="ms-auto sm:w-auto sm:px-6" loading={busy === 'commit'} disabled={toEnroll === 0} onClick={() => void commit()}>
              {t('enroll_rows', { count: toEnroll })}
            </Button>
          </div>
        </section>
      )}
    </div>
  )
}
