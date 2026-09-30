import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { authApi } from '../../api/auth'
import { tokenStore } from '../../api/client'
import type { SettingsGroup } from '../../api/settings'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { MobilePage } from '../../components/mobile/MobileChrome'
import { MAvatar, MCard, MEmpty, MListSkeleton, M_BTN_SECONDARY } from '../../components/mobile/atoms'
import { setLocale } from '../../lib/i18n'

/**
 * Settings below lg (mobile-redesign-spec.md §6.25): grouped lists. حسابي and العرض on top, then the authority's
 * setting groups; a group opens at ?group=<key> with the same GroupCard (fields, validation, save) as desktop.
 */

const GROUP_ICON: Record<string, string> = {
  authority: 'home', locale: 'globe', reminders: 'bell', attendance: 'attendance', registration: 'enroll', sessions: 'lessons',
  progress: 'evaluation', certificates: 'certificate', honor: 'medal', ui: 'eye', media: 'camera',
}

export default function MobileSettings({ groups, loading, error, onRetry, open, ornament, renderGroup }: {
  groups: SettingsGroup[]; loading: boolean; error: boolean; onRetry: () => void; open?: SettingsGroup; ornament?: string
  renderGroup: (g: SettingsGroup) => ReactNode
}) {
  const { t, i18n } = useTranslation('settings')
  const locale = i18n.language
  const { user } = useAuth()
  const crumbs = [{ label: t('mobile:home'), to: '/' }, { label: t('title'), to: '/settings' }]

  if (open) {
    return (
      <div className="space-y-4 lg:hidden">
        <MobilePage title={t(`groups.${open.key}.title`)} back="/settings" breadcrumb={[...crumbs, { label: t(`groups.${open.key}.title`) }]} />
        {renderGroup(open)}
      </div>
    )
  }

  const switchTo = (next: 'ar' | 'en') => {
    if (next === locale) return
    setLocale(next)
    if (tokenStore.get()) void authApi.updateLocale(next).catch(() => undefined)
  }
  const roles = user?.roles.map((r) => t(`common:roles.${r}`, r)).join(' · ')
  const row = 'flex min-h-[52px] items-center gap-3 px-4 py-2'
  const badge = 'inline-grid size-8 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700'

  return (
    <div className="space-y-6 lg:hidden">
      <MobilePage title={t('title')} back="/" breadcrumb={[crumbs[0], { label: t('title') }]} />

      <Group title={t('mobile.account')}>
        <li className={`${row} py-3`}>
          <MAvatar name={user?.name ?? '?'} size={44} />
          <span className="min-w-0 flex-1">
            <span className="block truncate text-[15px] font-semibold text-ink"><bdi>{user?.name}</bdi></span>
            {user?.phone && <span dir="ltr" className="block truncate text-start text-[13px] tabular-nums text-ink/65 rtl:text-end">{user.phone}</span>}
            {roles && <span className="block truncate text-[13px] text-ink/65">{roles}</span>}
          </span>
        </li>
      </Group>

      <Group title={t('mobile.display')}>
        <li className={row}>
          <span className={badge}><Icon name="globe" className="size-[18px]" /></span>
          <span id="m-settings-lang" className="min-w-0 flex-1 text-[15px] text-ink">{t('mobile.language')}</span>
          <div role="group" aria-labelledby="m-settings-lang" className="flex gap-[3px] rounded-ctl bg-ink/5 p-[3px]">
            {(['ar', 'en'] as const).map((l) => (
              <button key={l} type="button" lang={l} aria-pressed={locale === l} onClick={() => switchTo(l)}
                className={`h-9 min-w-16 rounded-lg px-3 text-[13px] font-semibold ${locale === l ? 'bg-white text-brand-700 shadow-card' : 'text-ink/65'}`}>
                {l === 'ar' ? 'العربية' : 'English'}
              </button>
            ))}
          </div>
        </li>
        {groups.some((g) => g.key === 'ui') && (
          <li>
            <Link to="/settings?group=ui" className={`${row} active:bg-brand-50/60`}>
              <span className={badge}><Icon name="eye" className="size-[18px]" /></span>
              <span className="min-w-0 flex-1 text-[15px] text-ink">{t('mobile.ornament')}</span>
              {ornament && <span className="shrink-0 text-[13px] text-ink/65">{t(`options.ui.ornament_level.${ornament}`, { defaultValue: ornament })}</span>}
              <Icon name="chevron" className="size-4 shrink-0 text-ink/40 rtl:rotate-180" />
            </Link>
          </li>
        )}
      </Group>

      {loading ? <MListSkeleton rows={6} /> : error ? (
        <MCard><MEmpty icon="alert" text={t('mobile.error')} action={<button type="button" onClick={onRetry} className={M_BTN_SECONDARY}>{t('common:retry')}</button>} /></MCard>
      ) : (
        <Group title={t('mobile.authority')}>
          {groups.map((g) => (
            <li key={g.key}>
              <Link to={`/settings?group=${g.key}`} className={`${row} py-2.5 active:bg-brand-50/60`}>
                <span className={badge}><Icon name={GROUP_ICON[g.key] ?? 'settings'} className="size-[18px]" /></span>
                <span className="min-w-0 flex-1">
                  <span className="block text-[15px] text-ink">{t(`groups.${g.key}.title`)}</span>
                  <span className="mt-0.5 line-clamp-2 text-[13px] text-ink/65">{t(`groups.${g.key}.desc`)}</span>
                </span>
                <Icon name="chevron" className="size-4 shrink-0 text-ink/40 rtl:rotate-180" />
              </Link>
            </li>
          ))}
        </Group>
      )}
    </div>
  )
}

function Group({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-2">
      <h2 className="px-1 text-xs font-semibold text-ink/65">{title}</h2>
      <ul className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">{children}</ul>
    </section>
  )
}
