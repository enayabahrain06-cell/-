import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { lessonsApi, optionsApi } from '../../api/lessons'
import { lotteryApi, type LotteryDetail, type LotteryInput } from '../../api/lottery'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { EmptyState, OrnamentDivider, PageBand } from '../../components/ornaments'
import { Badge, buttonClass, Card, CardTitle, ErrorState, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, TextInput, type Tone, SURFACE, inputClass, EmptyCard } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'
import { GENDER_TONE } from '../lessons/LessonsHomePage'

const STATUS_TONE: Record<string, Tone> = { draft: 'muted', run: 'gold', approved: 'brand', cancelled: 'muted' }

export function LotteryListPage() {
  const { t, i18n } = useTranslation('lottery')
  const { t: tl } = useTranslation('lessons')
  const locale = i18n.language
  const { can } = useAuth()
  const navigate = useNavigate()
  const q = useQuery({ queryKey: ['lotteries', locale], queryFn: () => lotteryApi.list() })
  const [open, setOpen] = useState(false)
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-5">
      <PageBand title={t('title')} subtitle={t('subtitle')} actions={can('lottery.manage') ? <button type="button" onClick={() => setOpen(true)} className={buttonClass('onDeep')}>+ {t('new')}</button> : undefined} />
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <EmptyCard icon="lottery" title={t('empty')} />
      ) : (
        <ul className="grid gap-3 *:min-w-0 md:grid-cols-2">
          {q.data.data.map((l) => (
            <li key={l.id}>
              <Link to={`/lottery/${l.id}`} className={`${SURFACE} block p-4 hover:border-brand-500/40`}>
                <div className="flex items-start justify-between gap-2">
                  <p dir="auto" className="font-semibold text-ink">{l.name}</p>
                  <span className="flex gap-1"><Badge tone={STATUS_TONE[l.status]}>{t(`status.${l.status}`)}</Badge>{l.gender && <Badge tone={GENDER_TONE[l.gender]}>{tl(`gender.${l.gender}`)}</Badge>}</span>
                </div>
                <p dir="auto" className="mt-1 text-sm text-ink/60">{l.package?.name}</p>
                <p className="mt-2 text-sm text-ink/70">{t('counts', { pool: n(l.pool_count), teachers: n(l.teachers_count) })}{l.run_at && <> · {formatDate(l.run_at, locale, { day: 'numeric', month: 'short' })}</>}</p>
              </Link>
            </li>
          ))}
        </ul>
      )}
      {open && <LotteryDialog onClose={() => setOpen(false)} onSaved={(d) => { setOpen(false); navigate(`/lottery/${d.id}`) }} />}
    </div>
  )
}

function LotteryDialog({ lottery, onClose, onSaved }: { lottery?: LotteryDetail; onClose: () => void; onSaved: (d: LotteryDetail) => void }) {
  const { t } = useTranslation('lottery')
  const packages = useQuery({ queryKey: ['package-options'], queryFn: optionsApi.packages, staleTime: 5 * 60_000 })
  const [packageId, setPackageId] = useState<number>(lottery?.package?.id ?? 0)
  const lessons = useQuery({ queryKey: ['lessons', 'pkg', packageId], queryFn: () => lessonsApi.list({ package_id: packageId, per_page: 100 }), enabled: !!packageId })
  const [form, setForm] = useState<Omit<LotteryInput, 'package_id' | 'teachers'>>({ name: lottery?.name ?? '', keep_siblings: lottery?.keep_siblings ?? true, balance_ages: lottery?.balance_ages ?? true, balance_levels: lottery?.balance_levels ?? false })
  const [rows, setRows] = useState<Record<number, { on: boolean; capacity: number; teacher_id: number }>>({})
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!lessons.data) return
    setRows(Object.fromEntries(lessons.data.data.map((l) => {
      const existing = lottery?.teachers.find((x) => x.lesson.id === l.id)
      return [l.id, { on: lottery ? !!existing : l.status === 'active', capacity: existing?.capacity ?? Math.max(1, l.capacity - l.student_count), teacher_id: l.teacher_id }]
    })))
  }, [lessons.data, lottery])

  const save = useMutation({
    mutationFn: () => {
      const teachers = Object.entries(rows).filter(([, r]) => r.on).map(([lid, r]) => ({ lesson_id: Number(lid), teacher_id: r.teacher_id, capacity: r.capacity }))
      return lottery ? lotteryApi.update(lottery.id, { ...form, teachers }) : lotteryApi.create({ ...form, package_id: packageId, teachers })
    },
    onSuccess: onSaved,
    onError: (e) => { const p = parseApiError(e); setError(Object.values(p.fields)[0]?.[0] ?? p.message) },
  })

  return (
    <Modal wide title={lottery ? t('form.title_edit') : t('form.title_new')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton disabled={!packageId || !form.name || !Object.values(rows).some((r) => r.on)} loading={save.isPending} onClick={() => save.mutate()}>{t('form.save')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="grid gap-4 sm:grid-cols-2">
        <TextInput label={t('form.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} dir="auto" />
        <SelectField label={t('form.package')} value={packageId || ''} disabled={!!lottery} onChange={(e) => setPackageId(Number(e.target.value))}
          options={[{ value: '', label: t('form.choose_package') }, ...(packages.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))]} />
      </div>
      <fieldset className="space-y-2">
        <legend className="mb-1 text-sm font-medium text-ink/75">{t('form.options')}</legend>
        {(['keep_siblings', 'balance_ages', 'balance_levels'] as const).map((k) => (
          <label key={k} className="flex items-center gap-2 text-sm text-ink/80"><input type="checkbox" className="size-4 accent-brand-600" checked={form[k]} onChange={(e) => setForm({ ...form, [k]: e.target.checked })} />{t(`form.${k}`)}</label>
        ))}
      </fieldset>
      {packageId > 0 && (
        <fieldset>
          <legend className="mb-1 text-sm font-medium text-ink/75">{t('form.teachers')}</legend>
          <p className="mb-2 text-xs text-ink/50">{t('form.teachers_hint')}</p>
          {lessons.isLoading ? <LoadingState /> : (
            <ul className="divide-y divide-ink/6 rounded-xl border border-ink/10">
              {(lessons.data?.data ?? []).map((l) => {
                const r = rows[l.id]
                if (!r) return null
                return (
                  <li key={l.id} className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
                    <input type="checkbox" className="size-4 accent-brand-600" aria-label={t('form.include')} checked={r.on} onChange={(e) => setRows({ ...rows, [l.id]: { ...r, on: e.target.checked } })} />
                    <span dir="auto" className="min-w-0 flex-1 text-ink">{l.name}<span className="block text-xs text-ink/50">{l.teacher?.name}</span></span>
                    <label className="flex items-center gap-1 text-xs text-ink/60">{t('form.capacity')}
                      <input type="number" min={1} max={500} value={r.capacity} disabled={!r.on} onChange={(e) => setRows({ ...rows, [l.id]: { ...r, capacity: Number(e.target.value) } })} className={inputClass('sm', 'w-16 text-center tabular-nums')} />
                    </label>
                  </li>
                )
              })}
            </ul>
          )}
        </fieldset>
      )}
    </Modal>
  )
}

export function LotteryDetailPage() {
  const { id } = useParams()
  const lotteryId = Number(id)
  const { t, i18n } = useTranslation('lottery')
  const { t: tl } = useTranslation('lessons')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['lottery', lotteryId, locale], queryFn: () => lotteryApi.show(lotteryId) })
  const [seed, setSeed] = useState('')
  const [notify, setNotify] = useState(true)
  const [edit, setEdit] = useState(false)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error' | 'info'; text: string } | null>(null)
  const set = (d: LotteryDetail) => { qc.setQueryData(['lottery', lotteryId, locale], d); void qc.invalidateQueries({ queryKey: ['lotteries'] }) }
  const onErr = (e: unknown) => setNotice({ tone: 'error', text: parseApiError(e).message })
  const n = (v: number) => formatNumber(v, locale)

  const run = useMutation({ mutationFn: () => lotteryApi.run(lotteryId, seed || undefined), onSuccess: (r) => { set(r.data); setNotice(r.run.split_families.length ? { tone: 'info', text: t('detail.split', { n: n(r.run.split_families.length) }) } : null) }, onError: onErr })
  const move = useMutation({ mutationFn: ({ rid, to }: { rid: number; to: number }) => lotteryApi.move(lotteryId, rid, to), onSuccess: set, onError: onErr })
  const approve = useMutation({ mutationFn: () => lotteryApi.approve(lotteryId, notify), onSuccess: (r) => { set(r.data); setNotice({ tone: 'success', text: t('detail.approved', { enrolled: n(r.result.enrolled), skipped: n(r.result.skipped), notified: n(r.result.notified) }) }) }, onError: onErr })
  const cancel = useMutation({ mutationFn: () => lotteryApi.cancel(lotteryId), onSuccess: () => void q.refetch(), onError: onErr })
  const pool = useMutation({ mutationFn: () => lotteryApi.syncPool(lotteryId), onSuccess: set, onError: onErr })

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} />
  const l = q.data
  const manage = can('lottery.manage')
  const editable = manage && (l.status === 'draft' || l.status === 'run')

  return (
    <div className="space-y-5">
      <Link to="/lottery" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline"><Icon name="chevron" className="size-4 ltr:rotate-180" />{t('detail.back')}</Link>
      <header className={`${SURFACE} p-4 sm:p-5`}>
        <div className="flex flex-wrap items-start gap-3">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 dir="auto" className="font-display text-3xl text-ink">{l.name}</h1>
              <Badge tone={STATUS_TONE[l.status]}>{t(`status.${l.status}`)}</Badge>
              {l.gender && <Badge tone={GENDER_TONE[l.gender]}>{tl(`gender.${l.gender}`)}</Badge>}
            </div>
            <p dir="auto" className="mt-1 text-sm text-ink/60">{l.package?.name}{l.seed && <> · {t('detail.run_info', { run: n(l.run_count), seed: l.seed })}</>}</p>
          </div>
          {editable && <SecondaryButton onClick={() => setEdit(true)}><Icon name="settings" className="size-4" />{t('detail.edit')}</SecondaryButton>}
          {editable && <SecondaryButton className="text-danger" onClick={() => window.confirm(t('detail.cancel_confirm')) && cancel.mutate()}>{t('detail.cancel')}</SecondaryButton>}
        </div>
        <OrnamentDivider className="my-3 text-gold-500/70" />
        <div className="flex flex-wrap gap-2 text-sm">
          {l.keep_siblings && <Badge tone="brand">{t('form.keep_siblings')}</Badge>}
          {l.balance_ages && <Badge tone="info">{t('form.balance_ages')}</Badge>}
          {l.balance_levels && <Badge tone="gold">{t('form.balance_levels')}</Badge>}
        </div>
        {editable && (
          <div className="mt-4 flex flex-wrap items-end gap-3 border-t border-ink/6 pt-4">
            <TextInput className="w-48" label={t('detail.seed')} dir="ltr" value={seed} onChange={(e) => setSeed(e.target.value)} />
            <PrimaryButton loading={run.isPending} onClick={() => run.mutate()}><Icon name="lottery" className="size-4" />{l.run_count ? t('detail.rerun') : t('detail.run')}</PrimaryButton>
            <p className="w-full text-xs text-ink/50">{t('detail.seed_hint')}</p>
          </div>
        )}
      </header>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}

      {l.results_count === 0 ? (
        <Card>
          <CardTitle actions={editable && <SecondaryButton disabled={pool.isPending} onClick={() => pool.mutate()}><Icon name="refresh" className="size-4" />{t('detail.refresh_pool')}</SecondaryButton>}>{t('detail.pool')} <span className="text-ink/45">({n(l.pool.length)})</span></CardTitle>
          {l.pool.length === 0 ? <EmptyState size="sm" icon="students" title={t('detail.no_results')} /> : (
            <ul className="flex flex-wrap gap-2">{l.pool.map((s) => <li key={s.id} className="inline-flex items-center gap-2 rounded-full bg-page px-2 py-1 text-sm"><Avatar name={s.full_name} initial={s.initial} src={s.photo_url} gender={s.gender} size="sm" /><span dir="auto">{s.full_name}</span></li>)}</ul>
          )}
        </Card>
      ) : (
        <div className="grid gap-4 lg:grid-cols-3">
          {l.teachers.map((tc) => (
            <Card key={tc.lottery_teacher_id}>
              <CardTitle actions={<span className={`text-sm tabular-nums ${tc.assigned >= tc.capacity ? 'text-gold-700' : 'text-ink/60'}`}>{t('detail.of', { n: n(tc.assigned), c: n(tc.capacity) })}</span>}>
                <span dir="auto">{tc.teacher.name}</span><span dir="auto" className="block text-xs font-normal text-ink/50">{tc.lesson.name}</span>
              </CardTitle>
              <ul className="divide-y divide-ink/6">
                {tc.students.map((s) => (
                  <li key={s.result_id} className="flex items-center gap-2 py-2 text-sm">
                    <Avatar name={s.student.full_name} initial={s.student.initial} src={s.student.photo_url} gender={s.student.gender} size="sm" />
                    <span className="min-w-0 flex-1"><span dir="auto" className="block truncate text-ink">{s.student.full_name}</span>
                      <span className="block text-xs text-ink/50">{s.age_at_start !== null && t('detail.age', { n: n(s.age_at_start) })}{s.student.memorization_level && ` · ${s.student.memorization_level}`}</span></span>
                    {editable && l.teachers.length > 1 && (
                      <select aria-label={t('detail.move_to')} className={inputClass('sm', 'text-xs')} value="" onChange={(e) => e.target.value && move.mutate({ rid: s.result_id, to: Number(e.target.value) })}>
                        <option value="">{t('detail.move_to')}</option>
                        {l.teachers.filter((x) => x.lottery_teacher_id !== tc.lottery_teacher_id).map((x) => <option key={x.lottery_teacher_id} value={x.lottery_teacher_id}>{x.teacher.name}</option>)}
                      </select>
                    )}
                  </li>
                ))}
              </ul>
            </Card>
          ))}
          {l.unassigned.length > 0 && (
            <Card className="border-danger/30 lg:col-span-3">
              <CardTitle>{t('detail.unassigned')} <span className="text-ink/45">({n(l.unassigned.length)})</span></CardTitle>
              <ul className="flex flex-wrap gap-2">{l.unassigned.map((s) => <li key={s.id} dir="auto" className="rounded-full bg-danger/8 px-3 py-1 text-sm text-danger">{s.full_name}</li>)}</ul>
            </Card>
          )}
        </div>
      )}

      {manage && l.status === 'run' && (
        <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-gold-500/40 bg-gold-500/6 p-4">
          <label className="flex items-center gap-2 text-sm text-ink/80"><input type="checkbox" className="size-4 accent-brand-600" checked={notify} onChange={(e) => setNotify(e.target.checked)} />{t('detail.notify')}</label>
          <PrimaryButton className="ms-auto" loading={approve.isPending} onClick={() => window.confirm(t('detail.approve_confirm')) && approve.mutate()}>{t('detail.approve')}</PrimaryButton>
        </div>
      )}
      {edit && <LotteryDialog lottery={l} onClose={() => setEdit(false)} onSaved={(d) => { setEdit(false); set(d) }} />}
    </div>
  )
}
