import { useEffect, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { usersApi, type AdminUser, type UserFilters } from '../../api/users'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { Badge, ErrorState, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, type Tone, SURFACE, FilterBar, SearchInput, EmptyCard } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'

const ROLE_TONE: Record<string, Tone> = { super_admin: 'gold', supervisor: 'info', teacher: 'brand' }
const ALL_ROLES = ['super_admin', 'supervisor', 'teacher', 'student', 'guardian'] as const

/** First letter of the name itself, skipping an honorific such as «أ.» or «الأستاذة». */
const HONORIFIC = /^(?:أ\s*[./]|الأستاذة|الأستاذ|الشيخة|الشيخ|د\s*\.|mrs?\.?|ms\.?|dr\.?|sheikh)\s*/i
function initialOf(name: string): string {
  return name.trim().replace(HONORIFIC, '').trim().charAt(0) || name.trim().charAt(0)
}

export default function UsersList({ onEdit, onNotice }: { onEdit: (u: AdminUser) => void; onNotice: (m: string | null) => void }) {
  const { t, i18n } = useTranslation('users')
  const locale = i18n.language
  const { can, user: me } = useAuth()
  const qc = useQueryClient()
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<UserFilters>({ page: 1 })
  const [confirm, setConfirm] = useState<AdminUser | null>(null)
  const manage = can('users.manage')

  // Debounce the search box into the query filters.
  useEffect(() => {
    const id = setTimeout(() => setFilters((f) => (f.search === (search.trim() || undefined) ? f : { ...f, search: search.trim() || undefined, page: 1 })), 300)
    return () => clearTimeout(id)
  }, [search])

  const q = useQuery({ queryKey: ['admin-users', filters], queryFn: () => usersApi.list(filters), placeholderData: keepPreviousData })
  const toggle = useMutation({
    mutationFn: async (u: AdminUser) => { if (u.is_active) await usersApi.deactivate(u.id); else await usersApi.update(u.id, { is_active: true }) },
    onSuccess: (_, u) => {
      setConfirm(null)
      onNotice(t(u.is_active ? 'list.deactivated' : 'list.activated', { name: u.name }))
      void qc.invalidateQueries({ queryKey: ['admin-users'] })
    },
    onError: (e) => { setConfirm(null); onNotice(parseApiError(e).message) },
  })
  const set = (patch: Partial<UserFilters>) => setFilters((f) => ({ ...f, ...patch, page: 1 }))

  return (
    <div className="space-y-4">
      <FilterBar>
        <SearchInput className="sm:min-w-56 sm:flex-1" label={t('list.search')} value={search} onChange={(e) => setSearch(e.target.value)} />
        <SelectField className="w-full sm:w-44" label={t('list.role')} hideLabel value={filters.role ?? ''} onChange={(e) => set({ role: e.target.value || undefined })}
          options={[{ value: '', label: t('list.all_roles') }, ...ALL_ROLES.map((r) => ({ value: r, label: t(`roles.${r}`) }))]} />
        <SelectField className="w-full sm:w-40" label={t('list.status')} hideLabel value={filters.active ?? ''} onChange={(e) => set({ active: (e.target.value || undefined) as UserFilters['active'] })}
          options={[{ value: '', label: t('list.any_status') }, { value: '1', label: t('list.active') }, { value: '0', label: t('list.inactive') }]} />
      </FilterBar>

      {q.isLoading ? (
        <LoadingState />
      ) : q.isError || !q.data ? (
        <ErrorState onRetry={() => void q.refetch()} />
      ) : q.data.data.length === 0 ? (
        <EmptyCard icon="users" title={t('list.empty')} />
      ) : (
        <section className={`${SURFACE} ${q.isFetching ? 'opacity-70' : ''}`} aria-label={t('tabs.users')}>
          <p className="border-b border-ink/8 px-5 py-3 text-sm text-ink/55">{t('list.count', { n: formatNumber(q.data.meta.total, locale) })}</p>
          <ul className="divide-y divide-ink/6">
            {q.data.data.map((u) => {
              const staff = u.roles.some((r) => r === 'super_admin' || r === 'supervisor' || r === 'teacher')
              return (
                <li key={u.id} className={`flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 ${u.is_active ? '' : 'bg-ink/[0.02]'}`}>
                  <Avatar name={u.name} initial={initialOf(u.name)} gender={u.gender} size="sm" />
                  <div className="min-w-0 flex-1 basis-48">
                    <p dir="auto" className={`truncate text-start font-medium ${u.is_active ? 'text-ink' : 'text-ink/50'}`}>
                      {u.name}
                      {me?.id === u.id && <span className="ms-2 text-xs font-normal text-ink/45">({t('list.you')})</span>}
                    </p>
                    <p className="truncate text-start text-sm text-ink/55">
                      <span dir="ltr" className="tabular-nums">{u.phone}</span>
                      {u.email && <> · <span dir="ltr">{u.email}</span></>}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-1.5">
                    {u.roles.map((r) => <Badge key={r} tone={ROLE_TONE[r] ?? 'muted'}>{t(`roles.${r}`, { defaultValue: r })}</Badge>)}
                    {staff && <Badge tone="muted">{t(`tracks.${u.track}`)}</Badge>}
                    {!u.is_active && <Badge tone="danger">{t('list.inactive')}</Badge>}
                  </div>
                  <p className="w-36 text-xs text-ink/50 max-sm:w-auto">
                    {u.last_login_at ? t('list.last_login', { date: formatDate(u.last_login_at, locale, { day: 'numeric', month: 'short', year: 'numeric' }) }) : t('list.never_logged_in')}
                  </p>
                  {manage && (
                    <div className="flex gap-1.5">
                      <SecondaryButton className="px-2.5 py-1.5" onClick={() => onEdit(u)} aria-label={t('list.edit_named', { name: u.name })}>
                        <Icon name="edit" className="size-4" />
                        <span className="max-sm:sr-only">{t('list.edit')}</span>
                      </SecondaryButton>
                      {me?.id !== u.id && (
                        <SecondaryButton className="px-2.5 py-1.5" onClick={() => (u.is_active ? setConfirm(u) : toggle.mutate(u))} disabled={toggle.isPending && toggle.variables?.id === u.id}>
                          {u.is_active ? t('list.deactivate') : t('list.activate')}
                        </SecondaryButton>
                      )}
                    </div>
                  )}
                </li>
              )
            })}
          </ul>
          <div className="border-t border-ink/6 px-5 py-3 empty:hidden">
            <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={(p) => setFilters((f) => ({ ...f, page: p }))} />
          </div>
        </section>
      )}

      {confirm && (
        <Modal title={t('list.deactivate_title')} onClose={() => setConfirm(null)}
          footer={<>
            <SecondaryButton onClick={() => setConfirm(null)}>{t('form.cancel')}</SecondaryButton>
            <PrimaryButton tone="danger" loading={toggle.isPending} onClick={() => toggle.mutate(confirm)}>{t('list.deactivate')}</PrimaryButton>
          </>}>
          <Notice tone="info">{t('list.deactivate_body', { name: confirm.name })}</Notice>
        </Modal>
      )}
    </div>
  )
}
