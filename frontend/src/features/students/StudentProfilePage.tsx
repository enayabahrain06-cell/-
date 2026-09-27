import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { studentsApi } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import { OrnamentDivider, StarSpinner } from '../../components/ornaments'
import { formatMoney, formatNumber, formatPercent } from '../../lib/format'
import { AttendanceTab, DetailsTab, EvaluationTab, IssuesTab, OverviewTab, WalletTab } from './profile/ProfileTabs'
import StudentCertificatesTab from '../certificates/StudentCertificatesTab'
import { openObjectUrl, studentReportObjectUrl } from '../../api/certificates'
import { ErrorState, SURFACE } from '../../components/ui'

const TABS = ['overview', 'evaluation', 'issues', 'attendance', 'wallet', 'certificates', 'details'] as const
type Tab = (typeof TABS)[number]

export default function StudentProfilePage() {
  const { id } = useParams()
  const studentId = Number(id)
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const [report, setReport] = useState<'idle' | 'loading' | 'error'>('idle')

  const profile = useQuery({ queryKey: ['student-profile', studentId, locale], queryFn: () => studentsApi.profile(studentId), enabled: Number.isFinite(studentId) })
  const detail = useQuery({ queryKey: ['student', studentId, locale], queryFn: () => studentsApi.show(studentId), enabled: Number.isFinite(studentId) })

  const readOnly = profile.data?.meta.read_only ?? true
  const tabs = TABS.filter((k) => (k === 'wallet' ? can('wallets.view') : true))
  const tab: Tab = (tabs as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'overview'

  if (profile.isLoading || detail.isLoading) {
    return <div className="grid place-items-center py-24"><StarSpinner className="size-10 text-brand-600" /></div>
  }
  if (profile.isError || !profile.data || !detail.data) {
    return (
      <div className="space-y-5">
        <BackLink />
        <ErrorState message={t('error')} />
      </div>
    )
  }

  const p = profile.data.data
  const h = p.header
  const current = h.position.current
  const n = (v: number) => formatNumber(v, locale)
  // Added to the profile header by the certificates module (16).
  const awards = h as typeof h & { certificates_count?: number; badges_count?: number }

  const printReport = async () => {
    setReport('loading')
    try {
      openObjectUrl(await studentReportObjectUrl(studentId))
      setReport('idle')
    } catch {
      setReport('error')
    }
  }

  return (
    <div className="space-y-5">
      <BackLink />

      <header className={`${SURFACE} relative overflow-hidden`}>
        <div className="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:p-6">
          <Avatar name={h.full_name} initial={h.initial} src={h.photo_url} gender={h.gender} size="lg" />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 dir="auto" className="font-display text-3xl text-ink">{h.full_name}</h1>
              {readOnly && <span className="rounded-full bg-ink/6 px-2.5 py-0.5 text-xs text-ink/60">{t('profile.read_only')}</span>}
            </div>
            <p className="mt-1 text-sm text-ink/60">
              <span className="tabular-nums">{h.student_no}</span>
              {h.age !== null && <> · {t('profile.age', { age: n(h.age) })}</>}
              {h.package && <> · <span dir="auto">{h.package.name}</span></>}
            </p>
            <p className="mt-0.5 text-sm text-ink/60">
              {h.lesson ? <span dir="auto">{h.lesson.name}</span> : t('no_circle')}
              {h.teacher && <> · <span dir="auto">{h.teacher}</span></>}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2 sm:flex-col sm:items-end">
            <div className="flex gap-2 text-sm">
              <button type="button" onClick={() => { const next = new URLSearchParams(params); next.set('tab', 'certificates'); setParams(next, { replace: true }) }}
                className="inline-flex items-center gap-1.5 rounded-full bg-gold-500/12 px-3 py-1 font-medium text-gold-700 hover:bg-gold-500/20">
                <Icon name="certificate" className="size-4" />
                <span className="tabular-nums">{n(awards.certificates_count ?? 0)}</span>
                <span>{t('profile.certificates')}</span>
              </button>
              <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 font-medium text-brand-700">
                <Icon name="evaluation" className="size-4" />
                <span className="tabular-nums">{n(awards.badges_count ?? 0)}</span>
                <span>{t('profile.badges')}</span>
              </span>
            </div>
            <button type="button" onClick={() => void printReport()} disabled={report === 'loading'} aria-busy={report === 'loading'}
              className="inline-flex items-center gap-1.5 rounded-xl border border-ink/12 bg-white px-3 py-2 text-sm font-medium text-ink/80 hover:bg-ink/5 disabled:opacity-60">
              {report === 'loading' ? <StarSpinner className="size-4" /> : <Icon name="printer" className="size-4" />}
              {t('profile.print_report')}
            </button>
            {report === 'error' && <p role="alert" className="text-xs text-danger">{t('profile.report_error')}</p>}
          </div>
        </div>
        <OrnamentDivider className="px-5 text-gold-500/60 sm:px-6" />
        <dl className="grid grid-cols-2 gap-px bg-ink/6 sm:grid-cols-4">
          <HeaderStat label={t('profile.position')}>
            {current ? (
              <>
                <span className="block">{current.surah_name} {n(current.ayah)}</span>
                <span className="block text-xs font-normal text-ink/50">{t('filters.juz_n', { n: n(current.juz) })} · {t(`profile.direction.${h.position.direction}`)}</span>
              </>
            ) : (
              <span className="text-ink/50">{t('not_started')}</span>
            )}
          </HeaderStat>
          <HeaderStat label={t('profile.attendance')}>{formatPercent(h.attendance_percent, locale)}</HeaderStat>
          <HeaderStat label={t('profile.balance')}>
            <span className={h.is_due ? 'text-danger' : ''}>{formatMoney(h.balance_fils, locale)}</span>
            {h.is_due && <span className="ms-1.5 rounded-full bg-danger/10 px-2 py-0.5 align-middle text-xs font-medium text-danger">{t('due_badge')}</span>}
          </HeaderStat>
          <HeaderStat label={t('profile.open_issues')}>
            <span>{n(h.open_issues.total)}</span>
            <span className="ms-2 inline-flex gap-1 align-middle">
              {h.open_issues.by_severity.high > 0 && <span className="rounded-full bg-danger/10 px-1.5 text-xs text-danger">{n(h.open_issues.by_severity.high)}</span>}
              {h.open_issues.by_severity.medium > 0 && <span className="rounded-full bg-gold-500/12 px-1.5 text-xs text-gold-700">{n(h.open_issues.by_severity.medium)}</span>}
              {h.open_issues.by_severity.low > 0 && <span className="rounded-full bg-ink/6 px-1.5 text-xs text-ink/60">{n(h.open_issues.by_severity.low)}</span>}
            </span>
          </HeaderStat>
        </dl>
      </header>

      <div role="tablist" aria-label={t('title')} className="-mx-4 flex gap-1 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        {tabs.map((k) => (
          <button key={k} role="tab" type="button" id={`tab-${k}`} aria-selected={tab === k} aria-controls={`panel-${k}`}
            onClick={() => { const next = new URLSearchParams(params); next.set('tab', k); setParams(next, { replace: true }) }}
            className={`shrink-0 rounded-xl px-4 py-2 text-sm font-medium transition ${tab === k ? 'bg-brand-700 text-white shadow-sm' : 'text-ink/65 hover:bg-white hover:text-ink'}`}
          >
            {t(`tabs.${k}`)}
            {k === 'issues' && h.open_issues.total > 0 && <span className={`ms-1.5 rounded-full px-1.5 text-xs ${tab === k ? 'bg-white/20' : 'bg-danger/10 text-danger'}`}>{n(h.open_issues.total)}</span>}
          </button>
        ))}
      </div>

      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`}>
        {tab === 'overview' && <OverviewTab profile={p} />}
        {tab === 'evaluation' && <EvaluationTab profile={p} />}
        {tab === 'issues' && <IssuesTab profile={p} />}
        {tab === 'attendance' && <AttendanceTab studentId={studentId} />}
        {tab === 'wallet' && <WalletTab studentId={studentId} />}
        {tab === 'certificates' && <StudentCertificatesTab studentId={studentId} student={detail.data} />}
        {tab === 'details' && <DetailsTab student={detail.data} canEdit={!readOnly && can('students.manage')} canPhoto={can('students.photo')} />}
      </div>
    </div>
  )
}

function HeaderStat({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="bg-white px-5 py-3">
      <dt className="text-xs text-ink/55">{label}</dt>
      <dd className="mt-0.5 font-semibold tabular-nums text-ink">{children}</dd>
    </div>
  )
}

function BackLink() {
  const { t } = useTranslation('students')
  return (
    <Link to="/students" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
      <Icon name="chevron" className="size-4 ltr:rotate-180" />
      {t('back')}
    </Link>
  )
}
