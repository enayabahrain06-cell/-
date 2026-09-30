import { Fragment, useState, type ReactNode } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { quranApi, type ProgressEntry } from '../../api/attendance'
import { CRITERIA, evaluationsApi, type Criterion, type SavedScore, type Suggestion } from '../../api/evaluations'
import type { StudentSummary } from '../../api/students'
import Icon from '../../components/Icon'
import BottomSheet from '../../components/mobile/BottomSheet'
import { StickyActionBar } from '../../components/mobile/ActionBars'
import { MobilePage } from '../../components/mobile/MobileChrome'
import { MAvatar, MCard, MEmpty, MList, Pill, Skeleton, M_BTN_PRIMARY, M_BTN_SECONDARY, type PillTone } from '../../components/mobile/atoms'
import { formatNumber, formatPercent } from '../../lib/format'
import IssueDialog from './IssueDialog'
import { isComplete, type Draft } from './ScoreGrid'

/**
 * The recitation entry form below lg (mobile-redesign-spec.md §6.12): one student at a time — student strip, the four
 * 0–10 scores, today's memorized / revised ranges with a preview line, the note — and a sticky save with a "next
 * student" square. Drafts, saving and suggestions stay in the page (EvaluationSheetPage / the monthly tab), exactly
 * as the desktop ScoreGrid uses them.
 */

type RowState = 'saved' | 'changed' | 'empty'
const STATE_TONE: Record<RowState, PillTone> = { saved: 'ok', changed: 'warn', empty: 'neutral' }
const STATE_DOT: Record<RowState, string> = { saved: 'bg-brand-600', changed: 'bg-gold-500', empty: 'bg-ink/25' }

function rowState(d: Draft, saved: SavedScore | null | undefined): RowState {
  const touched = CRITERIA.some((c) => d[c] !== (saved?.[c] ?? null)) || d.note !== (saved?.note ?? '') || d.progress.length > 0
  if (touched) return 'changed'
  return saved ? 'saved' : 'empty'
}

const FIELD = 'h-12 w-full rounded-md border border-ink/10 bg-white px-3.5 text-[15px] text-ink'
const LABEL = 'mb-1.5 block text-[13px] font-medium text-ink/75'

export default function MobileScoreEntry({ students, drafts, saved, suggestions, threshold, withProgress, onChange, onSuggestionDone, onSave, saving, dirty, saveLabel }: {
  students: StudentSummary[]
  drafts: Record<number, Draft>
  saved: Record<number, SavedScore | null>
  suggestions: Suggestion[]
  threshold: number
  withProgress: boolean
  onChange: (studentId: number, patch: Partial<Draft>) => void
  onSuggestionDone: (s: Suggestion) => void
  onSave: () => void
  saving: boolean
  dirty: boolean
  saveLabel?: string
}) {
  const { t, i18n } = useTranslation('evaluation')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const [index, setIndex] = useState(0)
  const [picker, setPicker] = useState(false)
  const [dialog, setDialog] = useState<Suggestion | null>(null)
  const [sentIds, setSentIds] = useState<number[]>([])
  const send = useMutation({
    mutationFn: (evaluationId: number) => evaluationsApi.send(evaluationId),
    onSuccess: (_d, evaluationId) => setSentIds((ids) => [...ids, evaluationId]),
  })

  const rows = students.filter((s) => drafts[s.id])
  const complete = rows.filter((s) => isComplete(drafts[s.id])).length
  if (rows.length === 0) return null
  const i = Math.min(index, rows.length - 1)
  const st = rows[i]
  const d = drafts[st.id]
  const done = saved[st.id]
  const state = rowState(d, done)
  const total = isComplete(d) ? CRITERIA.reduce((a, c) => a + (d[c] ?? 0), 0) : null
  const anyLow = CRITERIA.some((c) => d[c] !== null && (d[c] as number) < threshold)
  const sent = done && (done.sent_to_guardian_at || sentIds.includes(done.id))
  const sug = suggestions.filter((s) => s.student_id === st.id)
  const next = rows[i + 1]
  const pct = Math.round((complete / rows.length) * 100)

  const goNext = () => {
    // "Save and next": the one save call stores every complete row, so a finished row is saved before moving on.
    if (state === 'changed' && isComplete(d) && !saving) onSave()
    setIndex(i + 1)
    window.scrollTo({ top: 0 })
  }

  return (
    <div className="space-y-4">
      <MCard>
        <div className="flex items-baseline justify-between gap-3">
          <h2 className="text-[15px] font-semibold tabular-nums text-ink">{t('scored', { n: n(complete), total: n(rows.length) })}</h2>
          <span className="text-[13px] font-semibold tabular-nums text-brand-700">{formatPercent(pct, locale)}</span>
        </div>
        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-ink/5" role="progressbar" aria-valuemin={0} aria-valuemax={rows.length} aria-valuenow={complete} aria-label={t('scored', { n: n(complete), total: n(rows.length) })}>
          <div className="h-full rounded-full bg-chart-present" style={{ width: `${pct}%` }} />
        </div>
      </MCard>

      {/* Student strip: who is being scored, where they are in the circle, and the picker for the whole roster. */}
      <MCard className="flex items-center gap-3 !p-3">
        <span className="relative shrink-0">
          <MAvatar name={st.full_name} src={st.photo_url} size={44} />
          <span aria-hidden className={`absolute -bottom-0.5 -end-0.5 size-3 rounded-full ring-2 ring-white ${STATE_DOT[state]}`} />
        </span>
        <div className="min-w-0 flex-1">
          <Link to={`/students/${st.id}`} title={st.full_name} className="block truncate text-[15px] font-semibold text-ink"><bdi>{st.full_name}</bdi></Link>
          <p className="mt-0.5 flex min-w-0 items-center gap-2 text-[13px] text-ink/65">
            <span className="shrink-0 tabular-nums">{t('mobile.position', { n: n(i + 1), total: n(rows.length) })}</span>
            <Pill tone={STATE_TONE[state]}>{t(`row_state.${state}`)}</Pill>
          </p>
        </div>
        <button type="button" onClick={() => setPicker(true)} aria-haspopup="dialog" aria-label={t('mobile.pick_student')} title={t('mobile.pick_student')}
          className="inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700">
          <Icon name="students" className="size-5" />
        </button>
      </MCard>

      <MCard>
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-[15px] font-semibold text-ink">{t('mobile.scores')}</h2>
          <span className={`inline-flex h-8 items-center rounded-full px-3 text-[13px] tabular-nums ${total === null ? 'bg-ink/5 text-ink/65' : anyLow ? 'bg-gold-500/12 text-gold-700' : 'bg-brand-50 text-brand-700'}`}>
            <span className="me-1">{t('total')}</span>
            <b className="text-[15px]">{total === null ? '—' : n(total)}</b><span>/{n(40)}</span>
          </span>
        </div>
        <div className="mt-3 grid grid-cols-2 gap-3">
          {CRITERIA.map((c) => {
            const v = d[c]
            const low = v !== null && v < threshold
            return (
              <label key={c} className="block min-w-0">
                <span className={LABEL}>{t(`criteria.${c}`)}</span>
                <select value={v ?? ''} onChange={(e) => onChange(st.id, { [c]: e.target.value === '' ? null : Number(e.target.value) } as Partial<Draft>)}
                  className={`${FIELD} text-center font-semibold tabular-nums ${low ? 'border-gold-500/60 text-gold-700' : ''}`}>
                  <option value="">—</option>
                  {Array.from({ length: 11 }, (_, k) => 10 - k).map((k) => <option key={k} value={k}>{n(k)}</option>)}
                </select>
                {low && <span className="mt-1 block text-xs text-gold-700">{t('below', { n: n(threshold) })}</span>}
              </label>
            )
          })}
        </div>
      </MCard>

      {withProgress && (
        <MCard>
          <h2 className="text-[15px] font-semibold text-ink">{t('ledger')}</h2>
          <div className="mt-3 space-y-4">
            {d.progress.map((p, k) => (
              <MobileRange key={k} value={p} idPrefix={`me-${st.id}-${k}`}
                onChange={(v) => onChange(st.id, { progress: d.progress.map((x, j) => (j === k ? v : x)) })}
                onRemove={() => onChange(st.id, { progress: d.progress.filter((_, j) => j !== k) })} />
            ))}
            {d.progress.length < 4 && (
              <button type="button" className="inline-flex min-h-11 items-center gap-1.5 text-start text-[15px] font-semibold text-info"
                onClick={() => onChange(st.id, { progress: [...d.progress, { type: 'memorized', surah_number: st.progress.surah ?? 114, from_ayah: 1, to_ayah: 1 }] })}>
                <Icon name="plus" className="size-4" />{t('add_range')}
              </button>
            )}
          </div>
        </MCard>
      )}

      <label className="block">
        <span className={LABEL}>{t('note')}</span>
        <textarea rows={3} value={d.note} dir="auto" onChange={(e) => onChange(st.id, { note: e.target.value })}
          className="w-full rounded-md border border-ink/10 bg-white px-3.5 py-3 text-[15px] text-ink" />
      </label>

      {sug.map((s) => (
        <div key={s.criterion} className="rounded-card border border-gold-500/30 bg-gold-500/12 p-4">
          <p className="flex items-start gap-2 text-[15px] text-ink"><Icon name="alert" className="mt-0.5 size-5 shrink-0 text-gold-700" />{s.message}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            <button type="button" onClick={() => setDialog(s)} className={M_BTN_SECONDARY}>{t('suggest.open')}</button>
            <button type="button" onClick={() => onSuggestionDone(s)} className="min-h-11 px-2 text-[13px] font-semibold text-ink/65">{t('suggest.dismiss')}</button>
          </div>
        </div>
      ))}

      {done && (
        sent
          ? <p className="flex min-h-11 items-center gap-2 text-[13px] font-semibold text-brand-700"><Icon name="check" className="size-4" />{t('sent')}</p>
          : <button type="button" disabled={send.isPending} onClick={() => send.mutate(done.id)} className={`${M_BTN_SECONDARY} w-full`}>
              <Icon name="messages" className="size-5" />{send.isPending && send.variables === done.id ? t('sending') : t('send')}
            </button>
      )}

      <StickyActionBar>
        <button type="button" disabled={saving || complete === 0} onClick={onSave} className={`${M_BTN_PRIMARY} relative min-w-0 flex-1`}>
          <span className="truncate">{saving ? t('saving') : saveLabel ?? t('mobile.save')}</span>
          {dirty && !saving && <span aria-hidden className="size-2 shrink-0 rounded-full bg-gold-400" />}
          {dirty && <span className="sr-only">{t('unsaved')}</span>}
        </button>
        {next && (
          <button type="button" onClick={goNext} aria-label={t('mobile.next', { name: next.full_name })} title={t('mobile.next', { name: next.full_name })}
            className="inline-grid size-12 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700">
            <Icon name="chevron" className="size-5 rtl:rotate-180" />
          </button>
        )}
      </StickyActionBar>

      <BottomSheet open={picker} onClose={() => setPicker(false)} title={t('mobile.pick_student')}>
        <MList label={t('students')}>
          {rows.map((s, k) => {
            const dd = drafts[s.id]
            const ss = rowState(dd, saved[s.id])
            const tt = isComplete(dd) ? CRITERIA.reduce((a, c) => a + (dd[c] ?? 0), 0) : null
            return (
              <li key={s.id}>
                <button type="button" aria-current={k === i ? 'true' : undefined} onClick={() => { setIndex(k); setPicker(false) }}
                  className={`flex min-h-16 w-full items-center gap-3 px-4 py-2.5 text-start ${k === i ? 'bg-brand-50/60' : ''}`}>
                  <MAvatar name={s.full_name} src={s.photo_url} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[15px] font-semibold text-ink"><bdi>{s.full_name}</bdi></span>
                    <span className="mt-0.5 block text-[13px] text-ink/65">{t(`row_state.${ss}`)}</span>
                  </span>
                  <span className="shrink-0 text-[15px] font-semibold tabular-nums text-ink">{tt === null ? '—' : n(tt)}</span>
                </button>
              </li>
            )
          })}
        </MList>
      </BottomSheet>

      {dialog && (
        <IssueDialog studentId={st.id} studentName={st.full_name}
          initial={{ category: dialog.category, evaluation_id: dialog.evaluation_id, lesson_id: dialog.lesson_id, severity: dialog.score < 4 ? 'high' : 'medium' }}
          onClose={() => setDialog(null)}
          onDone={() => { onSuggestionDone(dialog); setDialog(null) }} />
      )}
    </div>
  )
}

/** One memorized / revised range: (type | surah), (from | to) and a preview line in the Quran font. */
function MobileRange({ value, onChange, onRemove, idPrefix }: { value: ProgressEntry; onChange: (v: ProgressEntry) => void; onRemove: () => void; idPrefix: string }) {
  const { t, i18n } = useTranslation('common')
  const locale = i18n.language
  const surahs = useQuery({ queryKey: ['surahs', locale], queryFn: quranApi.surahs, staleTime: Infinity })
  const current = surahs.data?.find((s) => s.number === value.surah_number)
  const max = current?.ayah_count ?? 286
  // Same bounds as QuranRangePicker: inside the surah, "to" never before "from"; the server checks again.
  const clamp = (v: number) => Math.min(max, Math.max(1, Number.isFinite(v) ? v : 1))

  return (
    <fieldset className="space-y-3 border-b border-ink/10 pb-4 last:border-0">
      <div className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-3">
        <label className="block min-w-0" htmlFor={`${idPrefix}-type`}>
          <span className={LABEL}>{t('quran.type')}</span>
          <select id={`${idPrefix}-type`} value={value.type} onChange={(e) => onChange({ ...value, type: e.target.value as ProgressEntry['type'] })} className={FIELD}>
            <option value="memorized">{t('quran.memorized')}</option>
            <option value="revised">{t('quran.revised')}</option>
          </select>
        </label>
        <label className="block min-w-0" htmlFor={`${idPrefix}-surah`}>
          <span className={LABEL}>{t('quran.surah')}</span>
          <select id={`${idPrefix}-surah`} value={value.surah_number} className={FIELD}
            onChange={(e) => {
              const num = Number(e.target.value)
              const count = surahs.data?.find((s) => s.number === num)?.ayah_count ?? 1
              onChange({ ...value, surah_number: num, from_ayah: 1, to_ayah: count })
            }}>
            {(surahs.data ?? []).map((s) => <option key={s.number} value={s.number}>{formatNumber(s.number, locale)}. {s.name}</option>)}
          </select>
        </label>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <label className="block min-w-0" htmlFor={`${idPrefix}-from`}>
          <span className={LABEL}>{t('evaluation:mobile.from_ayah')}</span>
          <input id={`${idPrefix}-from`} type="number" inputMode="numeric" min={1} max={max} value={value.from_ayah}
            onChange={(e) => { const f = clamp(Number(e.target.value)); onChange({ ...value, from_ayah: f, to_ayah: Math.max(f, value.to_ayah) }) }}
            className={`${FIELD} text-center tabular-nums`} />
        </label>
        <label className="block min-w-0" htmlFor={`${idPrefix}-to`}>
          <span className={LABEL}>{t('evaluation:mobile.to_ayah')}</span>
          <input id={`${idPrefix}-to`} type="number" inputMode="numeric" min={value.from_ayah} max={max} value={value.to_ayah}
            onChange={(e) => onChange({ ...value, to_ayah: Math.max(value.from_ayah, clamp(Number(e.target.value))) })}
            className={`${FIELD} text-center tabular-nums`} />
        </label>
      </div>
      <div className="flex items-center gap-2 rounded-md bg-page px-3.5 py-2.5">
        {current ? (
          <p className="min-w-0 flex-1 text-[15px] text-ink">
            <span lang="ar" dir="rtl" className="font-quran text-lg">{current.name_ar}</span>
            <span className="text-[13px] text-ink/65"> · {t(value.type === 'memorized' ? 'quran.memorized' : 'quran.revised')} · <span className="tabular-nums">{t('evaluation:mobile.ayahs', { from: formatNumber(value.from_ayah, locale), to: formatNumber(value.to_ayah, locale) })}</span></span>
          </p>
        ) : <Skeleton className="h-5 flex-1" />}
        <button type="button" onClick={onRemove} aria-label={t('quran.remove')} title={t('quran.remove')} className="-me-2 inline-grid size-11 shrink-0 place-items-center rounded-ctl text-danger">
          <Icon name="trash" className="size-5" />
        </button>
      </div>
    </fieldset>
  )
}

/** Header (back to the day, breadcrumb) and the session line; also claims the header while the sheet loads (CLS). */
export function MobileSheetHeader({ title, date, meta, actions }: { title?: string; date?: string; meta?: (string | null)[]; actions?: ReactNode }) {
  const { t } = useTranslation('evaluation')
  const back = date ? `/evaluation?date=${date}` : '/evaluation'
  const crumbs = [{ label: t('mobile:home'), to: '/' }, { label: t('title'), to: back }]
  return (
    <>
      <MobilePage title={title ?? t('title')} back={back} actions={actions} breadcrumb={title ? [...crumbs, { label: title }] : crumbs} />
      {meta && <p className="text-[13px] text-ink/65 lg:hidden">{meta.filter(Boolean).map((m, k) => <Fragment key={k}>{k > 0 && ' · '}<bdi>{m}</bdi></Fragment>)}</p>}
    </>
  )
}

/** "Set a score for everyone" in a bottom sheet (the desktop band's four selects). */
export function MobileSetAll({ open, onClose, onSet }: { open: boolean; onClose: () => void; onSet: (c: Criterion, v: number) => void }) {
  const { t, i18n } = useTranslation('evaluation')
  return (
    <BottomSheet open={open} onClose={onClose} title={t('set_all')}
      footer={<button type="button" onClick={onClose} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('mobile.done')}</button>}>
      <div className="grid grid-cols-2 gap-3">
        {CRITERIA.map((c) => (
          <label key={c} className="block min-w-0">
            <span className={LABEL}>{t(`criteria.${c}`)}</span>
            <select value="" onChange={(e) => e.target.value !== '' && onSet(c, Number(e.target.value))} className={`${FIELD} text-center tabular-nums`}>
              <option value="">—</option>
              {Array.from({ length: 11 }, (_, k) => 10 - k).map((k) => <option key={k} value={k}>{formatNumber(k, i18n.language)}</option>)}
            </select>
          </label>
        ))}
      </div>
    </BottomSheet>
  )
}

/** Skeleton of the entry form, same shape as the loaded page. */
export function MobileEntrySkeleton() {
  return (
    <div aria-hidden className="space-y-4 lg:hidden">
      <MCard><Skeleton className="h-5 w-1/2" /><Skeleton className="mt-3 h-1.5 w-full" /></MCard>
      <MCard className="flex items-center gap-3 !p-3"><Skeleton className="size-11 rounded-full" /><span className="flex-1 space-y-2"><Skeleton className="h-4 w-2/3" /><Skeleton className="h-3 w-1/3" /></span></MCard>
      <MCard><Skeleton className="h-5 w-1/3" /><div className="mt-3 grid grid-cols-2 gap-3">{[0, 1, 2, 3].map((k) => <Skeleton key={k} className="h-12" />)}</div></MCard>
    </div>
  )
}

export function MobileEntryEmpty({ text }: { text: string }) {
  return <MCard className="lg:hidden"><MEmpty icon="students" text={text} /></MCard>
}
