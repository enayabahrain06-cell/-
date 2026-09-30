import { Fragment, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useEmbed } from '../../app/embed'
import Icon from '../Icon'
import type { Crumb } from '../mobile/chrome'
import OrnamentPattern from './OrnamentPattern'
import OrnamentDivider from './OrnamentDivider'

/**
 * Deep emerald header band with a low-opacity gold girih pattern, for dashboards and home screens.
 * `breadcrumb` adds the trail above the band (the last crumb is the current page); `actions` sit on the end side.
 * Inside a nav_v2 tab page the tab page draws the one band: a hosted page's subtitle and actions move into it.
 */
export default function PageBand({ title, subtitle, actions, breadcrumb }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode; breadcrumb?: Crumb[] }) {
  const host = useEmbed()
  if (host) {
    return (
      <>
        {subtitle && host.bannerSubtitle && createPortal(subtitle, host.bannerSubtitle)}
        {actions && host.bannerActions && createPortal(actions, host.bannerActions)}
      </>
    )
  }
  const band = (
    <header className="relative overflow-hidden rounded-2xl bg-deep px-5 py-5 text-white shadow-sm sm:px-7">
      <OrnamentPattern />
      <div className="relative flex flex-wrap items-end justify-between gap-4">
        <div className="min-w-0">
          <h1 className="font-display text-3xl text-gold-300">{title}</h1>
          <OrnamentDivider className="mt-2 text-gold-400" />
          {subtitle && <div className="mt-2 text-sm text-white/85">{subtitle}</div>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </header>
  )
  if (!breadcrumb?.length) return band
  return (
    <div className="space-y-2">
      <Breadcrumb items={breadcrumb} />
      {band}
    </div>
  )
}

/** One-line trail of links: لوحة التحكم › section › page. The last crumb is the current page. */
export function Breadcrumb({ items }: { items: Crumb[] }) {
  const { t } = useTranslation('mobile')
  return (
    <nav aria-label={t('breadcrumb')}>
      <ol className="flex flex-wrap items-center gap-1 text-xs text-ink/60">
        {items.map((c, i) => (
          <Fragment key={i}>
            {i > 0 && <li aria-hidden><Icon name="chevron" className="size-3.5 text-ink/40 rtl:rotate-180" /></li>}
            <li className="min-w-0">
              {c.to && i < items.length - 1
                ? <Link to={c.to} className="rounded text-info-700 hover:underline">{c.label}</Link>
                : <span aria-current="page" className="font-medium text-ink/75">{c.label}</span>}
            </li>
          </Fragment>
        ))}
      </ol>
    </nav>
  )
}

/** Page title with the ornamental divider, for light pages. */
export function PageTitle({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <div className={className}>
      <h1 className="font-display text-3xl text-ink">{children}</h1>
      <OrnamentDivider className="mt-2 text-gold-500" />
    </div>
  )
}
