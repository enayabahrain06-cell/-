import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { AdminUser, RoleRow, UserFilters } from '../../api/users'
import type { Paginated } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import BottomSheet from '../../components/mobile/BottomSheet'
import MPager from '../../components/mobile/MPager'
import MSwitch from '../../components/mobile/MSwitch'
import { Chip, ChipRow, MAvatar, MCard, MEmpty, MListSkeleton, MSearch, Pill, M_BTN_PRIMARY, M_BTN_SECONDARY, type PillTone } from '../../components/mobile/atoms'
import { Fab, StickyActionBar } from '../../components/mobile/ActionBars'
import { formatDate, formatNumber } from '../../lib/format'

/** Users & permissions below lg (mobile-redesign-spec.md §6.24). Queries, mutations and dialogs stay in UsersList / PermissionMatrix. */

const ROLE_PILL: Record<string, PillTone> = { super_admin: 'info', supervisor: 'info', teacher: 'ok', accountant: 'warn' }
const ALL_ROLES = ['super_admin', 'supervisor', 'teacher', 'student', 'guardian'] as const
/** Initials from the name itself, skipping an honorific (same rule as the desktop list). */
const HONORIFIC = /^(?:أ\s*[./]|الأستاذة|الأستاذ|الشيخة|الشيخ|د\s*\.|mrs?\.?|ms\.?|dr\.?|sheikh)\s*/i

export function MobileUsersList({ data, loading, error, onRetry, search, onSearch, filters, set, onPage, onEdit, onToggle, toggling, onNew }: {
  data: Paginated<AdminUser> | undefined; loading: boolean; error: boolean; onRetry: () => void
  search: string; onSearch: (v: string) => void; filters: UserFilters; set: (p: Partial<UserFilters>) => void; onPage: (p: number) => void
  onEdit: (u: AdminUser) => void; onToggle: (u: AdminUser) => void; toggling: number | null; onNew?: () => void
}) {
  const { t, i18n } = useTranslation('users')
  const locale = i18n.language
  const { can, user: me } = useAuth()
  const manage = can('users.manage')
  const [open, setOpen] = useState<AdminUser | null>(null)

  return (
    <div className="space-y-3 lg:hidden">
      <MSearch label={t('list.search')} value={search} onChange={onSearch} />
      <ChipRow label={t('list.role')}>
        <Chip active={!filters.role} onClick={() => set({ role: undefined })}>{t('mobile.all')}</Chip>
        {ALL_ROLES.map((r) => <Chip key={r} active={filters.role === r} onClick={() => set({ role: filters.role === r ? undefined : r })}>{t(`roles.${r}`)}</Chip>)}
        <Chip active={filters.active === '0'} onClick={() => set({ active: filters.active === '0' ? undefined : '0' })}>{t('list.inactive')}</Chip>
      </ChipRow>

      {loading ? <MListSkeleton rows={6} /> : error || !data ? (
        <MCard><MEmpty icon="alert" text={t('mobile.error')} action={<button type="button" onClick={onRetry} className={M_BTN_SECONDARY}>{t('common:retry')}</button>} /></MCard>
      ) : data.data.length === 0 ? <MCard><MEmpty icon="users" text={t('list.empty')} /></MCard> : (
        <>
          <p className="text-[13px] tabular-nums text-ink/65">{t('list.count', { n: formatNumber(data.meta.total, locale) })}</p>
          <ul aria-label={t('tabs.users')} className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">
            {data.data.map((u) => {
              const role = u.roles[0]
              const body = (
                <>
                  <MAvatar name={u.name.trim().replace(HONORIFIC, '') || u.name} />
                  <span className="min-w-0 flex-1">
                    <span className="flex items-baseline gap-1.5">
                      <span className="truncate text-[15px] font-semibold text-ink"><bdi>{u.name}</bdi></span>
                      {me?.id === u.id && <span className="shrink-0 text-xs text-ink/65">({t('list.you')})</span>}
                    </span>
                    <span className="mt-0.5 block truncate text-[13px] text-ink/65"><span dir="ltr" className="tabular-nums">{u.phone}</span></span>
                    <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
                      {role && <Pill tone={ROLE_PILL[role] ?? 'neutral'}>{t(`roles.${role}`, { defaultValue: role })}{u.roles.length > 1 && <> +{formatNumber(u.roles.length - 1, locale)}</>}</Pill>}
                      {!u.is_active && <Pill tone="err">{t('list.inactive')}</Pill>}
                      <span className="min-w-0 truncate text-xs tabular-nums text-ink/65">{u.last_login_at ? t('list.last_login', { date: formatDate(u.last_login_at, locale, { day: 'numeric', month: 'short' }) }) : t('list.never_logged_in')}</span>
                    </span>
                  </span>
                </>
              )
              const cls = `flex min-h-16 w-full items-center gap-3 px-4 py-2.5 text-start ${u.is_active ? '' : 'opacity-70'}`
              return (
                <li key={u.id}>
                  {manage
                    ? <button type="button" onClick={() => setOpen(u)} className={`${cls} active:bg-brand-50/60`} aria-label={t('list.edit_named', { name: u.name })}>{body}<Icon name="chevron" className="size-4 shrink-0 text-ink/40 rtl:rotate-180" /></button>
                    : <div className={cls}>{body}</div>}
                </li>
              )
            })}
          </ul>
          <MPager page={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onPage={onPage} />
        </>
      )}

      {can('audit.view') && (
        <p className="flex items-start gap-2 rounded-card bg-info/10 px-4 py-3 text-[13px] text-info">
          <Icon name="eye" className="mt-0.5 size-4 shrink-0" />
          <span>{t('mobile.audit_note')} <Link to="/audit" className="inline-flex min-h-6 items-center font-semibold underline">{t('mobile.audit_link')}</Link></span>
        </p>
      )}

      {onNew && <Fab label={t('new_user')} onClick={onNew} />}

      <BottomSheet open={!!open} onClose={() => setOpen(null)} title={open?.name ?? ''}>
        {open && (
          <ul className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white">
            <li>
              <button type="button" onClick={() => { const u = open; setOpen(null); onEdit(u) }} className="flex min-h-[52px] w-full items-center gap-3 px-4 text-[15px] text-ink">
                <Icon name="edit" className="size-5 text-brand-700" />{t('list.edit')}
              </button>
            </li>
            {me?.id !== open.id && (
              <li>
                <button type="button" disabled={toggling === open.id} onClick={() => { const u = open; setOpen(null); onToggle(u) }}
                  className={`flex min-h-[52px] w-full items-center gap-3 px-4 text-[15px] disabled:opacity-60 ${open.is_active ? 'text-danger' : 'text-ink'}`}>
                  <Icon name={open.is_active ? 'ban' : 'check'} className={`size-5 ${open.is_active ? '' : 'text-brand-700'}`} />{open.is_active ? t('list.deactivate') : t('list.activate')}
                </button>
              </li>
            )}
          </ul>
        )}
      </BottomSheet>
    </div>
  )
}

/** Permission matrix below lg: one role at a time (chips), modules as cards with switches; sticky save when edited. */
export function MobileMatrix({ roles, groups, has, setMany, locked, isDirty, dirtyCount, saving, onSave, onDiscard }: {
  roles: RoleRow[]; groups: [string, { name: string; label: string }[]][]; has: (r: RoleRow, p: string) => boolean
  setMany: (r: RoleRow, names: string[], on: boolean) => void; locked: (r: RoleRow) => boolean; isDirty: (r: RoleRow) => boolean
  dirtyCount: number; saving: boolean; onSave: () => void; onDiscard: () => void
}) {
  const { t, i18n } = useTranslation('users')
  const locale = i18n.language
  const [roleId, setRoleId] = useState<number | null>(null)
  const role = roles.find((r) => r.id === roleId) ?? roles.find((r) => r.name !== 'super_admin') ?? roles[0]
  if (!role) return null
  const off = locked(role)

  return (
    <div className="space-y-3 lg:hidden">
      <ChipRow label={t('tabs.roles')}>
        {roles.map((r) => <Chip key={r.id} active={r.id === role.id} onClick={() => setRoleId(r.id)}>{r.label}{isDirty(r) && <span aria-hidden className="size-1.5 rounded-full bg-gold-500" />}</Chip>)}
      </ChipRow>
      {role.name === 'super_admin' && <p className="text-[13px] text-ink/65">{t('matrix.locked')}</p>}
      {groups.map(([module, perms]) => {
        const names = perms.map((p) => p.name)
        const n = names.filter((p) => has(role, p)).length
        const title = t(`modules.${module}`, { defaultValue: module })
        return (
          <section key={module} className="overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">
            <div className="flex min-h-12 items-center gap-3 border-b border-ink/10 bg-ink/5 ps-4 pe-1">
              <h3 id={`m-${module}`} className="min-w-0 flex-1 truncate text-[13px] font-semibold text-ink">{title}</h3>
              <span className="shrink-0 text-xs tabular-nums text-ink/65">{formatNumber(n, locale)}/{formatNumber(names.length, locale)}</span>
              <MSwitch checked={n === names.length} disabled={off} label={t('matrix.group_toggle', { module: title, role: role.label })} onChange={(on) => setMany(role, names, on)} />
            </div>
            <ul className="divide-y divide-ink/10">
              {perms.map((p) => (
                <li key={p.name} className="flex min-h-14 items-center gap-3 py-1.5 ps-4 pe-1">
                  <span id={`p-${role.id}-${p.name}`} className="min-w-0 flex-1">
                    <span className="block text-[15px] text-ink">{p.label}</span>
                    <span dir="ltr" className="block truncate text-start font-mono text-xs text-ink/65">{p.name}</span>
                  </span>
                  <MSwitch checked={has(role, p.name)} disabled={off} labelledBy={`p-${role.id}-${p.name}`} onChange={(on) => setMany(role, [p.name], on)} />
                </li>
              ))}
            </ul>
          </section>
        )
      })}
      {dirtyCount > 0 && (
        <StickyActionBar>
          <button type="button" disabled={saving} onClick={onDiscard} className={`${M_BTN_SECONDARY} h-12`}>{t('matrix.discard')}</button>
          <button type="button" disabled={saving} aria-busy={saving} onClick={onSave} className={`${M_BTN_PRIMARY} flex-1`}>{t('matrix.save')}</button>
        </StickyActionBar>
      )}
    </div>
  )
}
