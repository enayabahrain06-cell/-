import { Fragment, type ReactNode } from 'react'
import { Link, NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import LanguageSwitcher from '../../components/LanguageSwitcher'
import { MAvatar } from '../../components/mobile/atoms'
import { LogoMark, OrnamentStrip } from '../../components/ornaments'
import { usePortalTabs } from './hooks'

export interface Crumb { label: string; to?: string }

/**
 * Student / guardian shell, separate from the staff shell. Below lg: an app bar and a 4-tab bottom nav on the root
 * tabs, a back + title header with a breadcrumb on inner pages. From lg: a deep brand header with the same
 * destinations as a top nav, and the content in a centred reading column.
 */
export default function PortalLayout({ children, title, back, breadcrumb, actions }: { children: ReactNode; title?: string; back?: string; breadcrumb?: Crumb[]; actions?: ReactNode }) {
  const { t } = useTranslation('portal')
  const { user, signOut } = useAuth()
  const tabs = usePortalTabs()
  const inner = !!back

  const topLink = ({ isActive }: { isActive: boolean }) => `inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-medium ${isActive ? 'bg-white/15 text-white' : 'text-white/80 hover:text-white'}`

  return (
    <div className="min-h-screen bg-page">
      {/* Mobile header */}
      {inner ? (
        <div className="border-b border-ink/10 bg-page lg:hidden">
          <header className="flex h-14 items-center gap-1 pe-2 ps-1">
            <Link to={back} aria-label={t('mobile:back')} className="inline-grid size-11 shrink-0 place-items-center rounded-ctl text-brand-900">
              <Icon name="chevron" className="size-5 rtl:rotate-0 ltr:rotate-180" />
            </Link>
            <h1 className="min-w-0 flex-1 truncate font-display text-[22px] leading-normal text-brand-900"><bdi>{title}</bdi></h1>
            {actions && <div className="flex shrink-0 items-center">{actions}</div>}
          </header>
          {breadcrumb && breadcrumb.length > 0 && <Breadcrumb items={breadcrumb} className="px-4 pb-2" />}
        </div>
      ) : (
        <header className="flex h-14 items-center justify-between gap-3 border-b border-ink/10 bg-page px-4 lg:hidden">
          <Link to="/" className="flex min-w-0 items-center gap-2.5 rounded-ctl">
            <span className="inline-grid size-9 shrink-0 place-items-center rounded-[10px] bg-deep text-gold-400"><LogoMark className="size-6" /></span>
            <span className="truncate font-display text-xl leading-normal text-brand-900">{t('common:app_name')}</span>
          </Link>
          <Link to="/my-account" aria-label={t('tabs.account')} className="inline-grid size-11 shrink-0 place-items-center rounded-full">
            <MAvatar name={user?.name ?? '?'} size={34} />
          </Link>
        </header>
      )}

      {/* Desktop header */}
      <header className="hidden bg-deep text-gold-300 lg:block">
        <OrnamentStrip className="text-gold-400" />
        <div className="mx-auto flex max-w-5xl items-center gap-4 px-8 py-3">
          <Link to="/" className="flex shrink-0 items-center gap-2 rounded-lg">
            <LogoMark className="size-9" />
            <span className="font-display text-lg">{t('common:app_name')}</span>
          </Link>
          <nav className="flex min-w-0 flex-wrap gap-1" aria-label={t('nav_label')}>
            {tabs.filter((x) => x.key !== 'account').map((x) => <NavLink key={x.key} to={x.to} className={topLink}>{t(`tabs.${x.key}`)}</NavLink>)}
            <NavLink to="/my/exams" className={topLink}>{t('links.exams')}</NavLink>
            <NavLink to="/my-gallery" className={topLink}>{t('links.gallery')}</NavLink>
          </nav>
          <div className="ms-auto flex shrink-0 items-center gap-2">
            <LanguageSwitcher className="text-white/85" />
            <NavLink to="/my-account" className={topLink}>{t('tabs.account')}</NavLink>
            <button type="button" onClick={() => void signOut()} className="inline-grid size-10 place-items-center rounded-lg text-white/80 hover:bg-white/10" aria-label={t('common:logout')} title={t('common:logout')}><Icon name="logout" className="size-5" /></button>
          </div>
        </div>
      </header>

      <main className={`mx-auto w-full max-w-5xl px-4 pt-4 sm:px-6 lg:px-8 lg:pb-12 lg:pt-8 ${inner ? 'pb-10' : 'pb-28'}`}>
        {inner && (
          <div className="mb-5 hidden space-y-2 lg:block">
            {breadcrumb && breadcrumb.length > 0 && <Breadcrumb items={breadcrumb} />}
            <div className="flex items-center justify-between gap-3">
              <h1 className="min-w-0 truncate font-display text-3xl text-ink"><bdi>{title}</bdi></h1>
              {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
            </div>
          </div>
        )}
        {children}
      </main>

      {!inner && (
        <nav aria-label={t('mobile:bottom_nav')} className="fixed inset-x-0 bottom-0 z-30 border-t border-ink/10 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden">
          <div className="grid h-[76px] grid-cols-4 gap-1 px-2 pb-[14px] pt-1.5">
            {tabs.map((x) => (
              <NavLink key={x.key} to={x.to} end
                className={({ isActive }) => `relative flex min-h-11 flex-col items-center justify-center gap-1 pt-1 text-[11px] leading-[14px] ${isActive ? 'font-semibold text-brand-700 shadow-[inset_0_2px_0_var(--color-gold-500)]' : 'text-ink/65'}`}>
                <Icon name={x.icon} className="size-[22px]" />
                <span className="max-w-full truncate">{t(`tabs.${x.key}`)}</span>
              </NavLink>
            ))}
          </div>
        </nav>
      )}
    </div>
  )
}

function Breadcrumb({ items, className = '' }: { items: Crumb[]; className?: string }) {
  const { t } = useTranslation('mobile')
  return (
    <nav aria-label={t('breadcrumb')} className={`truncate text-xs text-ink/65 ${className}`}>
      {items.map((c, i) => (
        <Fragment key={i}>
          {i > 0 && <Icon name="chevron" className="mx-1 inline size-3 align-[-2px] rtl:rotate-180" />}
          {c.to ? <Link to={c.to} className="text-info">{c.label}</Link> : <span aria-current="page">{c.label}</span>}
        </Fragment>
      ))}
    </nav>
  )
}
