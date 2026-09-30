import { useMemo, useState, type KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { useMenuV2 } from '../../app/menuV2'
import { tabHref, viewName } from '../../app/navV2'
import { Modal, inputClass } from '../ui'

/** Arabic-insensitive search text: no diacritics or tatweel, one alef, ى → ي, ة → ه, Latin lower case. */
function fold(s: string): string {
  return s.toLowerCase()
    .replace(/[ً-ٰٟـ]/g, '')
    .replace(/[أإآٱ]/g, 'ا')
    .replace(/ى/g, 'ي')
    .replace(/ة/g, 'ه')
    .trim()
}

interface Hit { id: string; name: string; where: string; href: string; hay: string }

/**
 * nav_v2 quick search (Ctrl+K / ⌘K): every screen by its exact original name, in both languages, only the ones this
 * user may open. Choosing one opens its section, tab and mode directly. Mounted only while open, so it starts empty.
 */
export default function QuickSearch({ onClose }: { onClose: () => void }) {
  const { t, i18n } = useTranslation('nav')
  const navigate = useNavigate()
  const { sections } = useMenuV2()
  const [q, setQ] = useState('')
  const [at, setAt] = useState(0)

  const all = useMemo<Hit[]>(() => sections.flatMap(({ section, tabs }) => tabs.flatMap(({ tab, views }) => views.map((v) => {
    const sectionName = t(`sections.${section.key}`, { defaultValue: t(`v2.sections.${section.key}`) })
    const tabName = t(`v2.tabs.${section.key}.${tab.key}`)
    const name = viewName(v, i18n.language)
    return { id: v.id, name, where: `${sectionName} › ${tabName}`, href: tabHref(tab, v), hay: fold(`${v.name.ar} ${v.name.en} ${sectionName} ${tabName}`) }
  }))), [sections, t, i18n.language])

  const hits = useMemo(() => {
    const words = fold(q).split(/\s+/).filter(Boolean)
    const list = words.length ? all.filter((h) => words.every((w) => h.hay.includes(w))) : all
    return list
  }, [all, q])

  const choose = (h: Hit | undefined) => { if (!h) return; onClose(); navigate(h.href) }
  const onKey = (e: KeyboardEvent) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setAt((i) => Math.min(i + 1, hits.length - 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setAt((i) => Math.max(i - 1, 0)) }
    else if (e.key === 'Enter') { e.preventDefault(); choose(hits[at]) }
  }

  return (
    <Modal title={t('v2search.title')} onClose={onClose}>
      <input type="search" value={q} onChange={(e) => { setQ(e.target.value); setAt(0) }} onKeyDown={onKey} aria-label={t('v2search.title')}
        placeholder={t('v2search.placeholder')} role="combobox" aria-expanded aria-controls="v2-search-list" aria-activedescendant={hits[at] ? `v2-hit-${at}` : undefined}
        className={inputClass('md', 'w-full text-base')} />
      {hits.length === 0 ? <p className="py-6 text-center text-sm text-ink/60">{t('v2search.none')}</p> : (
        <ul id="v2-search-list" role="listbox" aria-label={t('v2search.title')} className="-mx-2 max-h-[55dvh] overflow-y-auto">
          {hits.map((h, i) => (
            <li key={h.id} id={`v2-hit-${i}`} role="option" aria-selected={i === at}>
              <button type="button" tabIndex={-1} onMouseEnter={() => setAt(i)} onClick={() => choose(h)}
                className={`flex min-h-11 w-full flex-col items-start justify-center rounded-lg px-3 py-1.5 text-start ${i === at ? 'bg-brand-50' : 'hover:bg-ink/5'}`}>
                <span className="text-sm font-medium text-ink">{h.name}</span>
                <span className="text-xs text-ink/60">{h.where}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  )
}

