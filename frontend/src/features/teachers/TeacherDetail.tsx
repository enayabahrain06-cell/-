import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { teachersApi, type TeacherDetail } from '../../api/teachers'
import { parseApiError } from '../../api/client'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import { EmptyState } from '../../components/ornaments'
import { Badge, buttonClass, Card, CardTitle, ErrorState, IconButton, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput, type Tone, SURFACE, ROW_MAIN } from '../../components/ui'
import { formatDate, formatNumber, formatPercent } from '../../lib/format'
import { initialOf } from './initial'
import { MobilePage } from '../../components/mobile/MobileChrome'

const STATUS_TONE: Record<string, Tone> = { active: 'brand', paused: 'gold', ended: 'muted' }

/** The teacher's photo (initial when none). Teacher managers can change or remove it here. */
function TeacherPhoto({ teacher: d }: { teacher: TeacherDetail }) {
  const { t } = useTranslation('teachers')
  const qc = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const done = () => { setError(null); void qc.invalidateQueries({ queryKey: ['teacher', d.id] }); void qc.invalidateQueries({ queryKey: ['teachers'] }) }
  const fail = (e: unknown) => setError(parseApiError(e).message)
  const upload = useMutation({ mutationFn: (file: File) => teachersApi.uploadPhoto(d.id, file), onSuccess: done, onError: fail })
  const remove = useMutation({ mutationFn: () => teachersApi.removePhoto(d.id), onSuccess: done, onError: fail })
  const busy = upload.isPending || remove.isPending

  return (
    <div className="flex shrink-0 flex-col items-center gap-1.5">
      <Avatar name={d.name} initial={initialOf(d.name)} src={d.photo.profile ?? d.photo.thumb} gender={d.gender} size="lg" />
      {d.can.edit && (
        <div className="flex items-center gap-1">
          <label className={`${buttonClass('secondary', 'py-1 text-xs')} cursor-pointer has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500 ${busy ? 'pointer-events-none opacity-60' : ''}`}>
            <Icon name="camera" className="size-4" />
            {upload.isPending ? t('photo.uploading') : d.photo.thumb ? t('photo.change') : t('photo.add')}
            <input type="file" accept="image/jpeg,image/png,image/heic,image/heif" className="sr-only" disabled={busy}
              onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f); e.target.value = '' }} />
          </label>
          {d.photo.thumb && (
            <IconButton icon="trash" tone="remove" label={t('photo.remove')} disabled={busy}
              onClick={() => window.confirm(t('photo.remove_confirm')) && remove.mutate()} />
          )}
        </div>
      )}
      {error && <p role="alert" className="max-w-48 text-center text-xs text-danger">{error}</p>}
    </div>
  )
}

/** One teacher: contact, this month's numbers, circles, weekly timetable and the next seven days. */
export default function TeacherDetailView({ id }: { id: number }) {
  const { t, i18n } = useTranslation('teachers')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['teacher', id, locale], queryFn: () => teachersApi.show(id) })
  const [editing, setEditing] = useState(false)
  const [saved, setSaved] = useState(false)

  // Below lg: back to the list with the teacher's name as the page title (claimed while loading too, no CLS).
  const crumbs = [{ label: t('mobile:home'), to: '/' }, { label: t('title'), to: '/teachers' }]
  const header = <MobilePage title={q.data?.name ?? t('title')} back="/teachers" breadcrumb={q.data ? [...crumbs, { label: q.data.name }] : crumbs} />
  if (q.isLoading) return <>{header}<LoadingState /></>
  if (q.isError || !q.data) return <>{header}<ErrorState message={t('error')} onRetry={() => void q.refetch()} /></>
  const d = q.data
  const n = (v: number) => formatNumber(v, locale)
  const score = (v: number | null) => (v === null ? '—' : formatNumber(v, locale, { maximumFractionDigits: 1 }))

  const kpis: [string, string][] = [
    [t('detail.kpi.students'), n(d.active_students)],
    [t('detail.kpi.sessions'), `${n(d.month.held)} / ${n(d.month.sessions_due)}`],
    [t('detail.kpi.taken_rate'), formatPercent(d.month.taken_rate, locale)],
    [t('detail.kpi.on_time'), formatPercent(d.month.on_time_rate, locale)],
    [t('detail.kpi.avg_evaluation'), score(d.month.avg_evaluation)],
    [t('detail.kpi.attendance_rate'), formatPercent(d.month.attendance_rate, locale)],
    [t('detail.kpi.evaluations'), n(d.evaluations_30d)],
  ]

  return (
    <div className="space-y-5">
      {header}
      <Link to="/teachers" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline">
        <Icon name="chevron" className="size-4 ltr:rotate-180" /> {t('detail.back')}
      </Link>
      <header className="flex flex-wrap items-center gap-4">
        <TeacherPhoto teacher={d} />
        <div className="min-w-0 flex-1 space-y-1">
          <h1 dir="auto" className="font-display text-3xl text-ink">{d.name}</h1>
          <p className="flex flex-wrap items-center gap-2 text-sm text-ink/60">
            <span dir="auto">{d.specialization || t('no_specialization')}</span>
            {d.gender && <Badge tone={d.gender === 'female' ? 'gold' : 'brand'}>{d.gender === 'female' ? t('filters.girls') : t('filters.boys')}</Badge>}
            <Badge tone={d.is_active ? 'brand' : 'muted'}>{d.is_active ? t('active') : t('inactive')}</Badge>
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {d.can.edit && <SecondaryButton onClick={() => setEditing(true)}><Icon name="edit" className="size-4" /> {t('detail.edit')}</SecondaryButton>}
          {d.can.edit_account && <Link to={`/users?search=${encodeURIComponent(d.phone ?? d.name)}`} className={buttonClass('secondary')}>{t('detail.edit_account')}</Link>}
        </div>
      </header>
      {saved && <Notice>{t('detail.saved')}</Notice>}

      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
        {kpis.map(([label, value]) => (
          <div key={label} className={`${SURFACE} p-4`}>
            <dt className="text-xs text-ink/60">{label}</dt>
            <dd className="mt-1 text-2xl font-semibold tabular-nums text-ink">{value}</dd>
          </div>
        ))}
      </dl>

      {/* min-w-0 on the columns lets the one-line (truncated) circle and session names shrink on phones. */}
      <div className="grid gap-5 *:min-w-0 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <Card>
            <CardTitle>{t('detail.circles')}</CardTitle>
            {d.circles.length === 0 ? <EmptyState size="sm" icon="lessons" title={t('detail.no_circles')} /> : (
              <ul className="divide-y divide-ink/6">
                {d.circles.map((c) => (
                  <li key={c.id}>
                    <Link to={`/lessons/${c.id}`} className="flex flex-wrap items-center gap-3 rounded-lg py-3 hover:bg-brand-50/40">
                      <div className={ROW_MAIN}>
                        <p dir="auto" className="truncate font-medium text-ink">{c.name}</p>
                        <p className="truncate text-xs text-ink/50">
                          <span dir="auto">{c.package}</span> · {c.days.map((day) => t(`detail.days.${day}`)).join('، ')} · {c.start_time}–{c.end_time}{c.location && <> · <span dir="auto">{c.location}</span></>}
                        </p>
                      </div>
                      <span className="text-sm tabular-nums text-ink/70">{c.capacity ? t('detail.capacity', { students: n(c.students), capacity: n(c.capacity) }) : n(c.students)}</span>
                      <Badge tone={STATUS_TONE[c.status]}>{t(`detail.status.${c.status}`)}</Badge>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <Card>
            <CardTitle>{t('detail.timetable')}</CardTitle>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
              {d.timetable.map((day) => (
                <div key={day.day} className="rounded-xl bg-page/60 p-3">
                  <p className="text-xs font-semibold text-ink/70">{t(`detail.days.${day.day}`)}</p>
                  {day.items.length === 0 ? <p className="mt-1 text-sm text-ink/40">{t('detail.free')}</p> : (
                    <ul className="mt-1 space-y-1">
                      {day.items.map((it) => (
                        <li key={`${it.lesson_id}-${it.start_time}`} className="text-sm">
                          <span className="tabular-nums text-ink/60">{it.start_time}</span> <span dir="auto" className="text-ink">{it.name}</span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              ))}
            </div>
          </Card>
        </div>
        <div className="space-y-5">
          <Card>
            <CardTitle>{t('detail.upcoming')}</CardTitle>
            {d.upcoming.length === 0 ? <p className="text-sm text-ink/55">{t('detail.no_upcoming')}</p> : (
              <ul className="space-y-2">
                {d.upcoming.map((s) => (
                  <li key={s.id} className="flex items-center gap-2 text-sm">
                    <div className="min-w-0 flex-1">
                      <p dir="auto" className="truncate text-ink">{s.lesson}</p>
                      <p className="text-xs text-ink/50">{formatDate(s.date, locale, { weekday: 'short', day: 'numeric', month: 'short' })} · {s.start_time}</p>
                    </div>
                    <Link to={`/messages/sessions/${s.id}`} className="rounded-lg p-1.5 text-ink/50 hover:bg-ink/5 hover:text-brand-700" aria-label={t('detail.messages')} title={t('detail.messages')}><Icon name="messages" className="size-4" /></Link>
                    {s.attendance_taken ? <Badge tone="brand">{t('detail.taken')}</Badge>
                      : d.can.take_attendance && <Link to={`/attendance/${s.id}`} className={buttonClass('secondary', 'text-xs')}>{t('detail.take')}</Link>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <Card>
            <CardTitle>{t('detail.bio')}</CardTitle>
            <dl className="space-y-2 text-sm">
              {d.phone && <div className="flex justify-between gap-2"><dt className="text-ink/60">{t('detail.phone')}</dt><dd dir="ltr" className="tabular-nums">{d.phone}</dd></div>}
              {d.email && <div className="flex justify-between gap-2"><dt className="text-ink/60">{t('detail.email')}</dt><dd dir="ltr" className="truncate">{d.email}</dd></div>}
              <div className="text-xs text-ink/50">{d.last_login_at ? t('detail.last_login', { date: formatDate(d.last_login_at, locale, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) }) : t('detail.never')}</div>
            </dl>
            {d.bio && <p dir="auto" className="mt-3 whitespace-pre-line text-sm text-ink/75">{d.bio}</p>}
          </Card>
        </div>
      </div>

      {editing && <EditDialog teacher={d} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); setSaved(true) }} />}
    </div>
  )
}

function EditDialog({ teacher, onClose, onSaved }: { teacher: TeacherDetail; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation('teachers')
  const qc = useQueryClient()
  const [specialization, setSpecialization] = useState(teacher.specialization ?? '')
  const [bio, setBio] = useState(teacher.bio ?? '')
  const [error, setError] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => teachersApi.update(teacher.id, { specialization: specialization.trim() || null, bio: bio.trim() || null }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['teacher', teacher.id] }); void qc.invalidateQueries({ queryKey: ['teachers'] }); onSaved() },
    onError: (e) => setError(parseApiError(e).message),
  })
  return (
    <Modal title={t('detail.edit')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('detail.cancel')}</SecondaryButton><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{t('detail.save')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      <TextInput label={t('detail.specialization')} value={specialization} maxLength={150} onChange={(e) => setSpecialization(e.target.value)} dir="auto" />
      <TextArea label={t('detail.bio')} rows={5} value={bio} maxLength={2000} onChange={(e) => setBio(e.target.value)} dir="auto" />
    </Modal>
  )
}
