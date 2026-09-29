import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { reportsApi, type CatalogEntry, type Cell, type Report, type ReportCatalog, type ReportFilter, type ReportParams } from '../../api/reports'
import { saveBlob } from '../../api/payments'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { Card, ErrorState, LoadingState, SearchInput, SecondaryButton, Segmented, TextInput, SURFACE } from '../../components/ui'
import { EmptyState, PageBand } from '../../components/ornaments'
import { formatNumber, formatPercent } from '../../lib/format'
import AttendanceRateChart from './AttendanceRateChart'
import ReportTable from './ReportTable'

/** Reports: a catalog grouped by subject; ?report=<key> opens one with its filters kept in the URL. */
export default function ReportsHomePage() {
  const { t, i18n } = useTranslation('reports')
  const [params] = useSearchParams()
  const catalog = useQuery({ queryKey: ['reports', 'catalog', i18n.language], queryFn: reportsApi.catalog })
  const key = params.get('report')
  const entry = catalog.data?.data.find((r) => r.key === key)

  return (
    <div className="space-y-5">
      <PageBand
        title={entry ? entry.title : t('title')}
        subtitle={entry ? entry.description : t('subtitle')}
        actions={
          entry && (
            <Link to="/reports" className="inline-flex items-center gap-1.5 rounded-lg border border-white/20 bg-white/10 px-3 py-1.5 text-sm text-white hover:bg-white/15">
              <Icon name="chevron" className="size-4 ltr:rotate-180" />
              {t('all_reports')}
            </Link>
          )
        }
      />
      {catalog.isLoading ? (
        <LoadingState />
      ) : catalog.isError || !catalog.data ? (
        <ErrorState onRetry={() => void catalog.refetch()} />
      ) : key && !entry ? (
        <EmptyState icon="reports" title={t('not_found')} />
      ) : entry ? (
        <Viewer key={entry.key} entry={entry} catalog={catalog.data} />
      ) : (
        <Catalog catalog={catalog.data} />
      )}
    </div>
  )
}

/** The catalog: one card per subject, laid out as balanced columns, with a client-side search over titles and descriptions. */
function Catalog({ catalog }: { catalog: ReportCatalog }) {
  const { t, i18n } = useTranslation('reports')
  const [query, setQuery] = useState('')
  const needle = query.trim().toLocaleLowerCase(i18n.language)
  const matches = (r: CatalogEntry) => !needle || `${r.title} ${r.description}`.toLocaleLowerCase(i18n.language).includes(needle)
  const groups = catalog.groups
    .map((g) => ({ ...g, reports: catalog.data.filter((r) => r.group === g.key && matches(r)) }))
    .filter((g) => g.reports.length > 0)

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        <SearchInput className="w-full sm:w-80" label={t('search')} value={query} onChange={(e) => setQuery(e.target.value)} />
        <p className="text-sm tabular-nums text-ink/55" aria-live="polite">
          {t('count', { n: formatNumber(groups.reduce((sum, g) => sum + g.reports.length, 0), i18n.language) })}
        </p>
      </div>

      {groups.length === 0 ? (
        <EmptyState icon="search" title={t('no_match')} />
      ) : (
        <div className="gap-5 lg:columns-2 2xl:columns-3 [&>*]:mb-5 [&>*]:break-inside-avoid">
          {groups.map((g) => (
            <section key={g.key} aria-labelledby={`group-${g.key}`} className={SURFACE}>
              <div className="flex items-center gap-2 border-b border-ink/6 px-4 py-3 sm:px-5">
                <h2 id={`group-${g.key}`} className="text-base font-semibold text-ink">{g.label}</h2>
                <span className="text-sm tabular-nums text-ink/45">({formatNumber(g.reports.length, i18n.language)})</span>
              </div>
              <ul className="divide-y divide-ink/6">
                {g.reports.map((r) => (
                  <li key={r.key}>
                    <Link
                      to={`/reports?report=${r.key}`}
                      className="group flex items-start gap-3 px-4 py-3.5 transition last:rounded-b-2xl hover:bg-brand-50/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500 sm:px-5"
                    >
                      <span className="rounded-xl bg-brand-50 p-2.5 text-brand-700">
                        <Icon name={r.icon} className="size-5" />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block font-semibold text-ink group-hover:text-brand-700">{r.title}</span>
                        <span className="mt-0.5 block text-sm leading-relaxed text-ink/60">{r.description}</span>
                      </span>
                      <Icon name="chevron" className="mt-3 size-4 shrink-0 text-ink/30 transition group-hover:text-brand-700 rtl:rotate-180" />
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}

const iso = (d: Date) => d.toLocaleDateString('en-CA')

/** This month, last month, and the school term (September–January or February–June), each up to today at most. */
function presets(): { key: string; from: string; to: string }[] {
  const now = new Date()
  const y = now.getFullYear()
  const m = now.getMonth()
  const termStart = m >= 8 ? new Date(y, 8, 1) : m === 0 ? new Date(y - 1, 8, 1) : new Date(y, 1, 1)
  return [
    { key: 'this_month', from: iso(new Date(y, m, 1)), to: iso(now) },
    { key: 'last_month', from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) },
    { key: 'this_term', from: iso(termStart), to: iso(now) },
  ]
}

const PARAM_KEYS = ['from', 'to', 'lesson_id', 'package_id', 'teacher_id', 'gender', 'min_absences', 'months', 'status'] as const

function Viewer({ entry, catalog }: { entry: CatalogEntry; catalog: ReportCatalog }) {
  const { t, i18n } = useTranslation('reports')
  const locale = i18n.language
  const [params, setParams] = useSearchParams()
  const [exporting, setExporting] = useState<'xlsx' | 'pdf' | null>(null)
  const [exportError, setExportError] = useState(false)
  const has = (f: ReportFilter) => entry.filters.includes(f)
  const month = presets()[0]

  const values: ReportParams = Object.fromEntries(PARAM_KEYS.map((k) => [k, params.get(k) ?? undefined]))
  if (has('period')) {
    values.from ??= month.from
    values.to ??= month.to
  }
  const set = (patch: ReportParams) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(patch)) {
      if (v) next.set(k, v)
      else next.delete(k)
    }
    setParams(next, { replace: true })
  }

  const q = useQuery({
    queryKey: ['reports', entry.key, values, locale],
    queryFn: () => reportsApi.get(entry.endpoint, values),
    placeholderData: keepPreviousData,
  })

  const exportAs = async (format: 'xlsx' | 'pdf') => {
    setExporting(format)
    setExportError(false)
    try {
      saveBlob(await reportsApi.export(entry.endpoint, values, format), `${entry.key}-report.${format}`, format === 'pdf')
    } catch {
      setExportError(true)
    } finally {
      setExporting(null)
    }
  }

  const o = catalog.options
  const lessons = o.lessons.filter((l) => !values.package_id || String(l.package_id) === values.package_id).filter((l) => !values.teacher_id || String(l.teacher_id) === values.teacher_id)
  const activePreset = presets().find((p) => p.from === values.from && p.to === values.to)?.key

  return (
    <div className="space-y-5">
      <div className={`${SURFACE} flex flex-wrap items-end gap-3 p-4`}>
        {has('period') && (
          <>
            <TextInput className="w-full sm:w-40" label={t('filters.from')} type="date" value={values.from} max={values.to} onChange={(e) => set({ from: e.target.value })} />
            <TextInput className="w-full sm:w-40" label={t('filters.to')} type="date" value={values.to} min={values.from} onChange={(e) => set({ to: e.target.value })} />
            <Segmented name="report-period" label={t('filters.presets')} value={activePreset ?? null}
              options={presets().map((p) => ({ value: p.key, label: t(`filters.${p.key}`) }))}
              onChange={(k) => { const p = presets().find((x) => x.key === k); if (p) set({ from: p.from, to: p.to }) }} />
          </>
        )}
        {has('months') && (
          <SelectField className="w-full sm:w-36" label={t('filters.months')} value={values.months ?? '12'} onChange={(e) => set({ months: e.target.value })}
            options={['3', '6', '12', '24'].map((m) => ({ value: m, label: t('filters.last_months', { n: formatNumber(Number(m), locale) }) }))} />
        )}
        {has('gender') && o.track === 'both' && (
          <SelectField className="w-full sm:w-36" label={t('filters.track')} value={values.gender ?? ''} onChange={(e) => set({ gender: e.target.value, lesson_id: undefined })}
            options={[{ value: '', label: t('filters.both_tracks') }, { value: 'male', label: t('filters.boys') }, { value: 'female', label: t('filters.girls') }]} />
        )}
        {has('package') && o.packages.length > 0 && (
          <SelectField className="w-full sm:w-52" label={t('filters.package')} value={values.package_id ?? ''} onChange={(e) => set({ package_id: e.target.value, lesson_id: undefined })}
            options={[{ value: '', label: t('filters.all_packages') }, ...o.packages.map((p) => ({ value: String(p.id), label: p.name }))]} />
        )}
        {has('teacher') && o.teachers.length > 1 && (
          <SelectField className="w-full sm:w-48" label={t('filters.teacher')} value={values.teacher_id ?? ''} onChange={(e) => set({ teacher_id: e.target.value, lesson_id: undefined })}
            options={[{ value: '', label: t('filters.all_teachers') }, ...o.teachers.map((x) => ({ value: String(x.id), label: x.name }))]} />
        )}
        {has('lesson') && lessons.length > 0 && (
          <SelectField className="w-full sm:w-52" label={t('filters.circle')} value={values.lesson_id ?? ''} onChange={(e) => set({ lesson_id: e.target.value })}
            options={[{ value: '', label: t('filters.all_circles') }, ...lessons.map((l) => ({ value: String(l.id), label: l.active ? l.name : `${l.name} (${t('filters.ended')})` }))]} />
        )}
        {has('min_absences') && (
          <TextInput className="w-full sm:w-32" label={t('filters.min_absences')} type="number" min={1} max={100} inputMode="numeric"
            value={values.min_absences ?? ''} placeholder={t('filters.default_limit')} onChange={(e) => set({ min_absences: e.target.value })} />
        )}
        {has('issue_status') && (
          <SelectField className="w-full sm:w-40" label={t('filters.status')} value={values.status ?? 'unresolved'} onChange={(e) => set({ status: e.target.value })}
            options={[{ value: 'unresolved', label: t('filters.open_only') }, { value: 'all', label: t('filters.all_statuses') }]} />
        )}
        {entry.formats.length > 0 && (
          <div className="ms-auto flex gap-2">
            {entry.formats.includes('xlsx') && (
              <SecondaryButton disabled={exporting !== null || !q.data} onClick={() => void exportAs('xlsx')}>
                <Icon name="table" className="size-4" />
                {exporting === 'xlsx' ? t('exporting') : t('export_xlsx')}
              </SecondaryButton>
            )}
            {entry.formats.includes('pdf') && (
              <SecondaryButton disabled={exporting !== null || !q.data} onClick={() => void exportAs('pdf')}>
                <Icon name="reports" className="size-4" />
                {exporting === 'pdf' ? t('exporting') : t('export_pdf')}
              </SecondaryButton>
            )}
          </div>
        )}
      </div>
      {exportError && <p role="alert" className="text-sm text-danger">{t('export_failed')}</p>}

      {q.isLoading ? (
        <LoadingState />
      ) : q.isError || !q.data ? (
        <ErrorState onRetry={() => void q.refetch()} />
      ) : (
        <ReportBody report={q.data} entry={entry} locale={locale} busy={q.isFetching} />
      )}
    </div>
  )
}

function summaryValue(v: Cell, locale: string): string {
  if (v === null || v === '') return '—'
  if (typeof v === 'number') return formatNumber(v, locale, { maximumFractionDigits: 1 })
  const pct = /^(\d+)%$/.exec(v)
  return pct ? formatPercent(Number(pct[1]), locale) : v
}

function ReportBody({ report, entry, locale, busy }: { report: Report; entry: CatalogEntry; locale: string; busy: boolean }) {
  const { t } = useTranslation('reports')
  const empty = report.sections.every((s) => s.rows.length === 0) && report.summary.every(([, v]) => v === 0 || v === '—' || v === null)

  return (
    <div className={`space-y-5 transition-opacity ${busy ? 'opacity-60' : ''}`} aria-busy={busy}>
      {report.period && <p className="text-sm text-ink/60">{report.period}</p>}

      {report.summary.length > 0 && (
        <section aria-label={t('summary')} className="grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))]">
          {report.summary.map(([label, value]) => (
            <div key={label} className={`${SURFACE} p-4`}>
              <p className="text-sm leading-snug text-ink/60">{label}</p>
              <p className="mt-1.5 text-xl font-semibold tabular-nums text-ink" dir="auto">{summaryValue(value, locale)}</p>
            </div>
          ))}
        </section>
      )}

      {empty ? (
        <EmptyState icon="reports" title={t('empty')} />
      ) : (
        <>
          {entry.chart === 'attendance_by_day' && report.data?.by_day && <AttendanceRateChart days={report.data.by_day} />}
          {report.sections.map((s) => (
            <Card key={s.key} aria-labelledby={`sec-${s.key}`}>
              <h2 id={`sec-${s.key}`} className="mb-3 text-base font-semibold text-ink">
                {s.title} <span className="ms-1 text-sm font-normal text-ink/45">({formatNumber(s.rows.length, locale)})</span>
              </h2>
              <ReportTable section={s} locale={locale} />
            </Card>
          ))}
        </>
      )}
    </div>
  )
}
