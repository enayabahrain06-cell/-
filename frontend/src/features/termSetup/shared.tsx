import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { termSetupApi, type CopyPart } from '../../api/termSetup'
import { useAuth } from '../../app/AuthContext'
import { useTerm } from '../../app/term'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { Modal, Notice, PrimaryButton, SecondaryButton } from '../../components/ui'

// eslint-disable-next-line react-refresh/only-export-components
export const WEEKDAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'] as const

/** Form choices for the selected term (levels, subjects, teachers, supervisors, halls, circles). */
// eslint-disable-next-line react-refresh/only-export-components
export function useSetupOptions() {
  return useQuery({ queryKey: ['term-setup-options'], queryFn: termSetupApi.options, staleTime: 60_000 })
}

// eslint-disable-next-line react-refresh/only-export-components
export function useCanManage() {
  return useAuth().can('term_setup.manage')
}

/** Which term is being set up; with "all terms" in the top bar the current term is used and said so. */
export function TermBar() {
  const { t } = useTranslation('termSetup')
  const { term } = useTerm()
  const options = useSetupOptions()
  const canManage = useCanManage()
  const [copy, setCopy] = useState(false)
  if (!options.data) return null

  return (
    <div className="flex flex-wrap items-center gap-3">
      <p className="text-sm text-ink/70">
        {t('term_bar', { name: options.data.term.name })}
        {term === 'all' && <span className="ms-1 text-gold-700">{t('term_bar_all')}</span>}
      </p>
      {canManage && (
        <SecondaryButton className="ms-auto" onClick={() => setCopy(true)}><Icon name="refresh" className="size-4" />{t('copy.open')}</SecondaryButton>
      )}
      {copy && <CopyDialog toTermId={options.data.term.id} onClose={() => setCopy(false)} />}
    </div>
  )
}

function CopyDialog({ toTermId, onClose }: { toTermId: number; onClose: () => void }) {
  const { t } = useTranslation('termSetup')
  const qc = useQueryClient()
  const { terms } = useTerm()
  const sources = terms.filter((x) => x.id !== toTermId)
  const [from, setFrom] = useState<number | ''>(sources[0]?.id ?? '')
  const [parts, setParts] = useState<CopyPart[]>(['level_rooms', 'level_subjects', 'night_supervisors', 'timetable'])
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const run = useMutation({
    mutationFn: () => termSetupApi.copy({ from_term_id: Number(from), to_term_id: toTermId, parts }),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); void qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('term-setup') }) },
    onError: (e) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })
  const toggle = (p: CopyPart) => setParts((ps) => (ps.includes(p) ? ps.filter((x) => x !== p) : [...ps, p]))

  return (
    <Modal title={t('copy.title')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('copy.close')}</SecondaryButton><PrimaryButton disabled={!from || parts.length === 0} loading={run.isPending} onClick={() => run.mutate()}>{t('copy.run')}</PrimaryButton></>}>
      {sources.length === 0 ? <Notice tone="info">{t('copy.no_source')}</Notice> : (
        <>
          <SelectField label={t('copy.from')} value={String(from)} onChange={(e) => setFrom(Number(e.target.value))}
            options={sources.map((x) => ({ value: String(x.id), label: x.name }))} />
          <fieldset className="space-y-2">
            <legend className="mb-1.5 text-sm font-medium text-ink/75">{t('copy.parts')}</legend>
            {(['level_rooms', 'level_subjects', 'night_supervisors', 'timetable'] as const).map((p) => (
              <label key={p} className="flex items-center gap-2 text-sm text-ink/80">
                <input type="checkbox" className="size-4 accent-brand-700" checked={parts.includes(p)} onChange={() => toggle(p)} />
                {t(`copy.part.${p}`)}
              </label>
            ))}
          </fieldset>
          <p className="text-xs text-ink/55">{t('copy.hint')}</p>
        </>
      )}
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
    </Modal>
  )
}
