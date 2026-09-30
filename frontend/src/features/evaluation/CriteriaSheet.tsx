import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { evaluationsApi, type CriteriaEntry, type SavedScore, type Sheet, type SheetCriterion } from '../../api/evaluations'
import { parseApiError } from '../../api/client'
import { EmptyCard, Notice, PrimaryButton, SURFACE, TABLE_HEAD, TableWrap, inputClass } from '../../components/ui'
import { formatNumber } from '../../lib/format'

type Draft = { scores: Record<number, number | null>; note: string }

const QURAN_KEYS = ['memorization', 'tajweed', 'revision', 'behavior'] as const

function draftFrom(criteria: SheetCriterion[], saved: SavedScore | null): Draft {
  const scores: Record<number, number | null> = {}
  for (const c of criteria) {
    const v = saved?.scores?.[String(c.id)] ?? (c.key && saved ? (saved as unknown as Record<string, number | null>)[c.key] ?? null : null)
    scores[c.id] = v ?? null
  }
  return { scores, note: saved?.note ?? '' }
}

/**
 * The evaluation sheet for a subject's own criteria (التقييمات): every subject other than Quran, and Quran once it has
 * criteria beyond its four. One row per student, one column per active criterion (0 to its maximum). Quran's four
 * system criteria are sent as the four columns, the rest as scores per criterion.
 */
export default function CriteriaSheet({ sheet, sessionId, divisionId }: { sheet: Sheet; sessionId: number; divisionId: number | null }) {
  const { t, i18n } = useTranslation('evaluation')
  const locale = i18n.language
  const qc = useQueryClient()
  const criteria = sheet.criteria ?? []
  const [drafts, setDrafts] = useState<Record<number, Draft>>({})
  const [msg, setMsg] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [dirty, setDirty] = useState(false)

  useEffect(() => {
    setDrafts(Object.fromEntries(sheet.data.map((r) => [r.student.id, draftFrom(criteria, r.evaluation)])))
    setDirty(false)
    // criteria come with the sheet
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sheet])

  const complete = (d?: Draft) => !!d && criteria.length > 0 && criteria.every((c) => d.scores[c.id] !== null && d.scores[c.id] !== undefined)
  const done = Object.values(drafts).filter(complete).length
  const maxTotal = criteria.reduce((a, c) => a + c.max_score, 0)

  const save = useMutation({
    mutationFn: () => {
      const entries: CriteriaEntry[] = Object.entries(drafts).filter(([, d]) => complete(d)).map(([sid, d]) => {
        const e: CriteriaEntry = { student_id: Number(sid), scores: {}, note: d.note || null }
        for (const c of criteria) {
          const v = d.scores[c.id] as number
          if (sheet.subject?.is_quran && c.is_system && c.key && (QURAN_KEYS as readonly string[]).includes(c.key)) (e as unknown as Record<string, number>)[c.key] = v
          else e.scores[c.id] = v
        }
        return e
      })
      return evaluationsApi.saveCriteria(sessionId, sheet.subject!.id, entries, divisionId)
    },
    onSuccess: (r) => {
      setDirty(false)
      setMsg({ tone: 'success', text: t('saved', { n: formatNumber(r.data.length, locale) }) })
      void qc.invalidateQueries({ queryKey: ['evaluation-sheet', sessionId] })
      void qc.invalidateQueries({ queryKey: ['student-profile'] })
    },
    onError: (e) => { const p = parseApiError(e); setMsg({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })

  const set = (sid: number, patch: Partial<Draft>) => { setDrafts((ds) => ({ ...ds, [sid]: { ...ds[sid], ...patch } })); setDirty(true); setMsg(null) }

  if (criteria.length === 0) return <EmptyCard icon="evaluation" title={t('criteria_sheet.no_criteria')} />
  if (sheet.data.length === 0) return <EmptyCard icon="students" title={t('empty_roster')} />

  return (
    <div className="space-y-4 pb-24 lg:pb-0">
      {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
      <TableWrap surface>
        <table className="w-full min-w-[36rem] text-sm">
          <thead className={TABLE_HEAD}>
            <tr>
              <th scope="col" className="px-3 py-2 text-start font-medium">{t('student')}</th>
              {criteria.map((c) => (
                <th key={c.id} scope="col" className="px-2 py-2 text-center font-medium">
                  <span dir="auto" className="block">{c.name}</span>
                  <span className="block text-[11px] font-normal text-ink/45">{t('criteria_sheet.out_of', { n: formatNumber(c.max_score, locale) })}</span>
                </th>
              ))}
              <th scope="col" className="px-2 py-2 text-center font-medium">{t('total')}</th>
              <th scope="col" className="px-3 py-2 text-start font-medium">{t('criteria_sheet.note')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-ink/6">
            {sheet.data.map(({ student }) => {
              const d = drafts[student.id]
              if (!d) return null
              const total = complete(d) ? criteria.reduce((a, c) => a + (d.scores[c.id] ?? 0), 0) : null
              return (
                <tr key={student.id}>
                  <td className="px-3 py-2">
                    <Link to={`/students/${student.id}`} dir="auto" className="block max-w-48 truncate font-medium text-ink hover:text-brand-700">{student.full_name}</Link>
                  </td>
                  {criteria.map((c) => (
                    <td key={c.id} className="px-2 py-2">
                      <label className="sr-only" htmlFor={`cs-${student.id}-${c.id}`}>{`${c.name}: ${student.full_name}`}</label>
                      <select id={`cs-${student.id}-${c.id}`} value={d.scores[c.id] ?? ''}
                        onChange={(e) => set(student.id, { scores: { ...d.scores, [c.id]: e.target.value === '' ? null : Number(e.target.value) } })}
                        className={inputClass('sm', 'select-chevron mx-auto min-h-10 w-20 appearance-none bg-[length:0.875rem] bg-[position:left_0.5rem_center] bg-no-repeat ps-2 pe-7 text-center font-semibold tabular-nums ltr:bg-[position:right_0.5rem_center]')}>
                        <option value="">—</option>
                        {Array.from({ length: c.max_score + 1 }, (_, i) => c.max_score - i).map((n) => <option key={n} value={n}>{formatNumber(n, locale)}</option>)}
                      </select>
                    </td>
                  ))}
                  <td className="px-2 py-2 text-center tabular-nums">
                    {total === null ? <span className="text-ink/40">—</span> : <><b>{formatNumber(total, locale)}</b><span className="text-ink/50">/{formatNumber(maxTotal, locale)}</span></>}
                  </td>
                  <td className="px-3 py-2">
                    <label className="sr-only" htmlFor={`cn-${student.id}`}>{t('criteria_sheet.note')}</label>
                    <input id={`cn-${student.id}`} dir="auto" value={d.note} onChange={(e) => set(student.id, { note: e.target.value })} className={inputClass('sm', 'w-44 text-sm')} />
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </TableWrap>
      <div className={`${SURFACE} sticky bottom-3 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 p-3`}>
        <span className="text-sm tabular-nums text-ink/60">{t('scored', { n: formatNumber(done, locale), total: formatNumber(sheet.data.length, locale) })}</span>
        {dirty && <span className="inline-flex items-center gap-1.5 text-sm text-gold-700"><span className="size-2 rounded-full bg-gold-500" />{t('unsaved')}</span>}
        <PrimaryButton className="ms-auto min-w-40" loading={save.isPending} disabled={done === 0} onClick={() => save.mutate()}>
          {save.isPending ? t('saving') : t('save')}
        </PrimaryButton>
      </div>
    </div>
  )
}
