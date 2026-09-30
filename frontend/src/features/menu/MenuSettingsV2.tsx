import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { MenuLayout } from '../../api/menu'
import { V2_SECTIONS, orderedTabs, tabHidden, viewName, type V2Section } from '../../app/navV2'
import { Badge, IconButton, Notice, PrimaryButton, SecondaryButton, SURFACE } from '../../components/ui'

const move = <T,>(list: T[], i: number, d: -1 | 1): T[] => {
  const j = i + d
  if (j < 0 || j >= list.length) return list
  const next = [...list]
  ;[next[i], next[j]] = [next[j], next[i]]
  return next
}

function sortedSections(order: string[]): V2Section[] {
  const pos = new Map(order.map((k, i) => [k, i]))
  return [...V2_SECTIONS].sort((a, b) => (pos.get(a.key) ?? order.length + V2_SECTIONS.indexOf(a)) - (pos.get(b.key) ?? order.length + V2_SECTIONS.indexOf(b)))
}

/**
 * القائمة for nav_v2: the order of the sections and of each section's tabs, and which tabs are hidden, for everyone.
 * Hiding is per tab: a tab is hidden when every feature it carries is hidden, and hiding it hides them all (the old
 * menu too). Features hidden one by one in the old menu whose tab is still shown are listed, since they show again.
 */
export default function MenuSettingsV2({ layout, saving, onSave }: { layout: MenuLayout; saving: boolean; onSave: (d: MenuLayout) => void }) {
  const { t, i18n } = useTranslation('menuSettings')
  const [draft, setDraft] = useState<{ sections: string[]; tabs: Record<string, string[]>; hidden: Set<string> } | null>(null)
  const sections = sortedSections(draft?.sections ?? layout.sections)
  const tabOrder = draft?.tabs ?? layout.tabs ?? {}
  const hidden = draft?.hidden ?? new Set(layout.hidden)
  const edit = (patch: Partial<{ sections: string[]; tabs: Record<string, string[]>; hidden: Set<string> }>) =>
    setDraft({ sections: sections.map((s) => s.key), tabs: tabOrder, hidden, ...patch })

  const toggle = (features: string[], hide: boolean) => {
    const n = new Set(hidden)
    for (const f of features) { if (hide) n.add(f); else n.delete(f) }
    edit({ hidden: n })
  }
  const save = () => onSave({ ...layout, sections: sections.map((s) => s.key), tabs: tabOrder, hidden: [...hidden] })
  const reset = () => { setDraft(null); onSave({ ...layout, sections: [], entries: {}, hidden: [], tabs: {} }) }

  // Hidden in the old menu one by one, while their tab still shows: they are visible again as a mode.
  const showsAgain = V2_SECTIONS.flatMap((s) => s.tabs.filter((tab) => !tabHidden(tab, hidden)).flatMap((tab) =>
    tab.views.filter((v) => v.feature && hidden.has(v.feature)).map((v) => ({ id: v.id, name: viewName(v, i18n.language), tab: t(`nav:v2.tabs.${s.key}.${tab.key}`) }))))

  return (
    <>
      <div className="flex flex-wrap justify-end gap-2">
        <SecondaryButton onClick={reset} disabled={saving}>{t('reset')}</SecondaryButton>
        <PrimaryButton loading={saving} onClick={save}>{t('save')}</PrimaryButton>
      </div>
      {showsAgain.length > 0 && (
        <Notice tone="info">
          <p className="font-medium">{t('v2.shows_again', { count: showsAgain.length })}</p>
          <ul className="mt-1 list-disc ps-5">{showsAgain.map((x) => <li key={x.id}>{x.name} <span className="text-ink/60">({x.tab})</span></li>)}</ul>
        </Notice>
      )}
      <ol className="space-y-3">
        {sections.map((s, si) => {
          const tabs = orderedTabs(s, tabOrder[s.key])
          return (
            <li key={s.key} className={`${SURFACE} p-4`}>
              <div className="flex items-center gap-2">
                <p className="min-w-0 flex-1 font-semibold text-ink">{t(`nav:sections.${s.key}`, { defaultValue: t(`nav:v2.sections.${s.key}`) })}</p>
                <IconButton icon="chevron" iconClassName="-rotate-90" label={t('up')} disabled={si === 0} onClick={() => edit({ sections: move(sections.map((x) => x.key), si, -1) })} />
                <IconButton icon="chevron" iconClassName="rotate-90" label={t('down')} disabled={si === sections.length - 1} onClick={() => edit({ sections: move(sections.map((x) => x.key), si, 1) })} />
              </div>
              <ul className="mt-2 divide-y divide-ink/6">
                {tabs.map((tab, ti) => {
                  const features = tab.views.filter((v) => v.feature).map((v) => v.feature as string)
                  const off = tabHidden(tab, hidden)
                  const keys = tabs.map((x) => x.key)
                  return (
                    <li key={tab.key} className={`flex items-center gap-2 py-2 text-sm ${off ? 'opacity-55' : ''}`}>
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-medium text-ink">{t(`nav:v2.tabs.${s.key}.${tab.key}`)}</p>
                        <p className="text-xs text-ink/60">{tab.views.map((v) => viewName(v, i18n.language)).join(t('v2.sep'))}</p>
                      </div>
                      {off && <Badge tone="gold">{t('hidden')}</Badge>}
                      <IconButton icon="eye" label={off ? t('show') : t('hide')} onClick={() => toggle(features, !off)} />
                      <IconButton icon="chevron" iconClassName="-rotate-90" label={t('up')} disabled={ti === 0} onClick={() => edit({ tabs: { ...tabOrder, [s.key]: move(keys, ti, -1) } })} />
                      <IconButton icon="chevron" iconClassName="rotate-90" label={t('down')} disabled={ti === tabs.length - 1} onClick={() => edit({ tabs: { ...tabOrder, [s.key]: move(keys, ti, 1) } })} />
                    </li>
                  )
                })}
              </ul>
            </li>
          )
        })}
      </ol>
    </>
  )
}
