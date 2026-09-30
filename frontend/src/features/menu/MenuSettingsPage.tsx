import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { menuApi, type MenuLayout } from '../../api/menu'
import { arrangeMenu, useMenuLayout } from '../../app/menu'
import type { MenuSectionDef } from '../../app/nav'
import { PageBand } from '../../components/ornaments'
import { Badge, ErrorState, IconButton, LoadingState, Notice, PrimaryButton, SecondaryButton, Segmented, SURFACE } from '../../components/ui'
import MenuSettingsV2 from './MenuSettingsV2'
import type { CrudNotice } from '../common/crud'

/**
 * القائمة: the order of the menu's sections and entries, and which entries are hidden, for everyone.
 * Names cannot be changed here (they are the exact feature names). Permissions still decide who sees an entry.
 * The switch at the top chooses the old menu or nav_v2 (sections with tab pages) for everyone, at run time.
 */
export default function MenuSettingsPage() {
  const { t } = useTranslation('menuSettings')
  const qc = useQueryClient()
  const q = useMenuLayout()
  // Unsaved edits; until the first edit the page shows the saved layout.
  const [draft, setDraft] = useState<{ sections: MenuSectionDef[]; hidden: Set<string> } | null>(null)
  const [notice, setNotice] = useState<CrudNotice>(null)
  const sections = draft?.sections ?? (q.data ? arrangeMenu(q.data, false) : null)
  const hidden = draft?.hidden ?? new Set(q.data?.hidden ?? [])
  const setSections = (next: MenuSectionDef[] | ((s: MenuSectionDef[]) => MenuSectionDef[])) =>
    setDraft({ sections: typeof next === 'function' ? next(sections ?? []) : next, hidden })
  const setHidden = (fn: (h: Set<string>) => Set<string>) => setDraft({ sections: sections ?? [], hidden: fn(hidden) })

  const layout = (): MenuLayout => ({
    sections: (sections ?? []).map((s) => s.key),
    entries: Object.fromEntries((sections ?? []).map((s) => [s.key, s.entries.map((e) => e.key)])),
    hidden: [...hidden],
  })
  const save = useMutation({
    mutationFn: (d: MenuLayout) => menuApi.save(d),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); qc.setQueryData(['menu-layout'], r.data) },
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })
  const reset = () => {
    setDraft({ sections: arrangeMenu({ sections: [], entries: {}, hidden: [] }, false), hidden: new Set() })
    save.mutate({ sections: [], entries: {}, hidden: [] })
  }

  const move = <T,>(list: T[], i: number, d: -1 | 1): T[] => {
    const j = i + d
    if (j < 0 || j >= list.length) return list
    const next = [...list]
    ;[next[i], next[j]] = [next[j], next[i]]
    return next
  }
  const moveEntry = (si: number, ei: number, d: -1 | 1) =>
    setSections((ss) => ss.map((s, i) => (i === si ? { ...s, entries: move(s.entries, ei, d) } : s)))
  const toggle = (key: string) => setHidden((h) => { const n = new Set(h); if (n.has(key)) n.delete(key); else n.add(key); return n })

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.menu')} subtitle={t('subtitle')} />
      </div>
      <p className="text-sm text-ink/60 lg:hidden">{t('subtitle')}</p>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {q.data && (
        <div className={`${SURFACE} space-y-2 p-4`}>
          <p className="font-semibold text-ink">{t('v2.switch')}</p>
          <p className="text-sm text-ink/65">{t('v2.switch_hint')}</p>
          <Segmented name="nav-v2" label={t('v2.switch')} value={q.data.nav_v2 ? 'v2' : 'old'}
            options={[{ value: 'old', label: t('v2.old') }, { value: 'v2', label: t('v2.new') }]}
            onChange={(v) => q.data && save.mutate({ ...q.data, nav_v2: v === 'v2' })} />
        </div>
      )}
      {q.data?.nav_v2 ? <MenuSettingsV2 key={JSON.stringify(q.data)} layout={q.data} saving={save.isPending} onSave={(d) => save.mutate(d)} /> : q.isLoading || !sections ? (q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : <LoadingState />) : (
        <>
          <div className="flex flex-wrap justify-end gap-2">
            <SecondaryButton onClick={reset} disabled={save.isPending}>{t('reset')}</SecondaryButton>
            <PrimaryButton loading={save.isPending} onClick={() => save.mutate(layout())}>{t('save')}</PrimaryButton>
          </div>
          <ol className="space-y-3">
            {sections.map((s, si) => (
              <li key={s.key} className={`${SURFACE} p-4`}>
                <div className="flex items-center gap-2">
                  <p className="min-w-0 flex-1 font-semibold text-ink">{t(`nav:sections.${s.key}`)}</p>
                  <IconButton icon="chevron" iconClassName="-rotate-90" label={t('up')} disabled={si === 0} onClick={() => setSections(move(sections, si, -1))} />
                  <IconButton icon="chevron" iconClassName="rotate-90" label={t('down')} disabled={si === sections.length - 1} onClick={() => setSections(move(sections, si, 1))} />
                </div>
                <ul className="mt-2 divide-y divide-ink/6">
                  {s.entries.map((e, ei) => {
                    const off = hidden.has(e.key)
                    return (
                      <li key={e.key} className={`flex items-center gap-2 py-1.5 text-sm ${off ? 'opacity-55' : ''}`}>
                        <span className="min-w-0 flex-1 truncate text-ink">{t(`nav:menu.${e.key}`)}</span>
                        {e.path === null && <Badge tone="muted">{t('not_built')}</Badge>}
                        {off && <Badge tone="gold">{t('hidden')}</Badge>}
                        <IconButton icon="eye" label={off ? t('show') : t('hide')} onClick={() => toggle(e.key)} />
                        <IconButton icon="chevron" iconClassName="-rotate-90" label={t('up')} disabled={ei === 0} onClick={() => moveEntry(si, ei, -1)} />
                        <IconButton icon="chevron" iconClassName="rotate-90" label={t('down')} disabled={ei === s.entries.length - 1} onClick={() => moveEntry(si, ei, 1)} />
                      </li>
                    )
                  })}
                </ul>
              </li>
            ))}
          </ol>
        </>
      )}
    </div>
  )
}
