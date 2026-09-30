import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useNavigate, useNavigationType, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { EmbedContext, type EmbedHost } from '../../app/embed'
import { useMenuV2 } from '../../app/menuV2'
import { V2_SECTIONS, kindsOf, modesOf, pickView, tabHref, viewName, visibleViews, type V2Section, type V2Tab, type V2View } from '../../app/navV2'
import Icon from '../../components/Icon'
import { MobilePage } from '../../components/mobile/MobileChrome'
import ModeSwitch from '../../components/nav/ModeSwitch'
import SectionTabs from '../../components/nav/SectionTabs'
import { PageBand } from '../../components/ornaments'
import { Segmented, buttonClass } from '../../components/ui'

/**
 * nav_v2 tab page (/<section>/<tab>): breadcrumb, the one banner (tab name, the exact name of the screen shown,
 * the mode switch on the end edge), the section's tab row, then the existing page of the chosen mode, hosted so it
 * keeps all of its behaviour. ?mode= picks the mode, ?kind= narrows it (الصف / التقسيم …).
 */
export default function TabPage({ sectionKey, tabKey, pages }: { sectionKey: string; tabKey: string; pages: Record<string, ReactNode> }) {
  const section = V2_SECTIONS.find((s) => s.key === sectionKey) as V2Section
  const tab = section.tabs.find((x) => x.key === tabKey) as V2Tab
  const { can } = useAuth()
  const views = useMemo(() => visibleViews(tab, can), [tab, can])
  if (views.length === 0) return <Navigate to="/" replace />
  return <Tab section={section} tab={tab} views={views} pages={pages} />
}

function Tab({ section, tab, views, pages }: { section: V2Section; tab: V2Tab; views: V2View[]; pages: Record<string, ReactNode> }) {
  const { t, i18n } = useTranslation('nav')
  const { can } = useAuth()
  const navigate = useNavigate()
  const navType = useNavigationType()
  const [params, setParams] = useSearchParams()
  const menu = useMenuV2()
  const [last, setLast] = useState<V2View | null>(null)

  // A hosted page may replace the whole query (setParams({ lesson }) with replace). That drops ?mode=; keep the mode
  // it was on. Links, the mode switch and back / forward (push and pop) always follow the URL.
  const asked = pickView(views, params)
  const keep = navType === 'REPLACE' && !params.get('mode') && last && views.includes(last) && last !== asked ? last : null
  const view = (keep ?? asked) as V2View
  if (view !== last) setLast(view)
  useEffect(() => {
    if (!keep) return
    setParams((p) => {
      const n = new URLSearchParams(p)
      if (modesOf(views).length > 1) n.set('mode', keep.mode)
      if (keep.kind && kindsOf(views, keep.mode).length > 1) n.set('kind', keep.kind)
      return n
    }, { replace: true })
  }, [keep, views, setParams])

  const [slots, setSlots] = useState<{ actions: HTMLElement | null; subtitle: HTMLElement | null; mobile: HTMLElement | null }>({ actions: null, subtitle: null, mobile: null })
  const setActions = useCallback((el: HTMLElement | null) => setSlots((s) => (s.actions === el ? s : { ...s, actions: el })), [])
  const setSubtitle = useCallback((el: HTMLElement | null) => setSlots((s) => (s.subtitle === el ? s : { ...s, subtitle: el })), [])
  const setMobile = useCallback((el: HTMLElement | null) => setSlots((s) => (s.mobile === el ? s : { ...s, mobile: el })), [])

  const go = useCallback((page: string, query: Record<string, string>, extra: Record<string, string> = {}) => {
    for (const s of V2_SECTIONS) for (const x of s.tabs) for (const w of x.views) {
      if (w.page !== page || !can(...w.permissions)) continue
      if (Object.entries(query).every(([k, val]) => (w.old.query[k] ?? '') === val)) {
        navigate(tabHref(x, w, new URLSearchParams(extra)))
        return
      }
    }
  }, [can, navigate])
  const own = useMemo(() => Object.fromEntries(Object.entries(view.old.query).filter(([, val]) => val !== '')), [view])
  const host = useMemo<EmbedHost>(() => ({ params: own, bannerActions: slots.actions, bannerSubtitle: slots.subtitle, mobileActions: slots.mobile, go }), [own, slots, go])

  const sectionName = t(`sections.${section.key}`, { defaultValue: t(`v2.sections.${section.key}`) })
  const tabName = t(`v2.tabs.${section.key}.${tab.key}`)
  const screenName = viewName(view, i18n.language)
  const sectionHref = menu.sections.find((s) => s.section.key === section.key)?.href ?? tabHref(tab)
  const crumbs = [{ label: t('menu.dashboard'), to: '/' }, { label: sectionName, to: sectionHref }, { label: tabName }]

  useEffect(() => {
    const before = document.title
    document.title = `${screenName} — ${sectionName} — ${t('common:app_name')}`
    return () => { document.title = before }
  }, [screenName, sectionName, t])

  // Mode switch (one option per mode the user may open) and the kind switch of the current mode.
  const modes = modesOf(views)
  const modeOptions = modes.map((m) => {
    const first = views.find((w) => w.mode === m) as V2View
    const target = m === view.mode ? view : first
    return { value: m, label: t(`v2.modes.${section.key}.${tab.key}.${m}`), href: tabHref(tab, target) }
  })
  const kinds = kindsOf(views, view.mode)
  const kindLabel = (k?: string) => t(`v2.kinds.${section.key}.${tab.key}.${k}`)
  // kindStyle 'button' (رصد الدرجات): the other kind is one button (تنزيل الدرجات ↔ عرض الدرجات), not a switch.
  const other = tab.kindStyle === 'button' && kinds.length > 1 ? kinds.find((k) => k !== view) : undefined
  const kindButton = (variant: 'onDeepGhost' | 'secondary') => other && (
    <button type="button" onClick={() => navigate(tabHref(tab, other))} className={buttonClass(variant)}>
      <Icon name={other.kind === 'download' ? 'download' : 'eye'} className="size-4" />{kindLabel(other.kind)}
    </button>
  )
  const kindSwitch = tab.kindStyle !== 'button' && kinds.length > 1 && (
    <Segmented name={`${section.key}-${tab.key}-kind`} label={t(`v2.kindLabels.${section.key}.${tab.key}`)} value={view.kind ?? null}
      options={kinds.map((k) => ({ value: k.kind as string, label: kindLabel(k.kind) }))}
      onChange={(v) => { const k = kinds.find((x) => x.kind === v); if (k) navigate(tabHref(tab, k)) }} />
  )
  const tabs = (menu.sections.find((s) => s.section.key === section.key)?.tabs ?? []).map((x) => ({
    key: x.tab.key, label: t(`v2.tabs.${section.key}.${x.tab.key}`), href: x.href, active: x.tab.key === tab.key,
  }))

  return (
    <>
      <MobilePage title={tabName} back={sectionHref === tabHref(tab) ? '/' : sectionHref} breadcrumb={crumbs} actions={<span ref={setMobile} className="contents" />} />
      <div className="space-y-5">
          <div className="hidden lg:block">
            <PageBand breadcrumb={crumbs} title={tabName}
              subtitle={<span className="flex flex-wrap items-center gap-x-2 gap-y-1"><span className="font-medium">{screenName}</span><span ref={setSubtitle} className="contents" /></span>}
              actions={<><span ref={setActions} className="contents" />{kindButton('onDeepGhost')}<ModeSwitch onDeep name={`${section.key}-${tab.key}-mode`} label={tabName} value={view.mode} options={modeOptions} /></>} />
          </div>
          <SectionTabs label={sectionName} tabs={tabs} />
          <div className="space-y-3 lg:hidden">
            <ModeSwitch name={`${section.key}-${tab.key}-mode-m`} label={tabName} value={view.mode} options={modeOptions} />
            {other && <div className="flex justify-end">{kindButton('secondary')}</div>}
          </div>
          {kindSwitch && <div>{kindSwitch}</div>}
          {/* Only the hosted page sees the host; this page's own band above is drawn normally. */}
          <EmbedContext.Provider value={host}><div key={view.id}>{pages[view.page]}</div></EmbedContext.Provider>
      </div>
    </>
  )
}
