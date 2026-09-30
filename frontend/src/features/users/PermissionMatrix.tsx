import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { usersApi, type RoleRow } from '../../api/users'
import { useAuth } from '../../app/AuthContext'
import { ErrorState, LoadingState, Notice, PrimaryButton, SecondaryButton, SURFACE } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { MobileMatrix } from './MobileUsers'

/** The Super Admin always holds every permission; the server refuses edits to it. */
const LOCKED = 'super_admin'

function GroupBox({ checked, indeterminate, disabled, label, onChange }: { checked: boolean; indeterminate: boolean; disabled: boolean; label: string; onChange: (v: boolean) => void }) {
  const ref = useRef<HTMLInputElement>(null)
  useEffect(() => { if (ref.current) ref.current.indeterminate = indeterminate }, [indeterminate])
  return <input ref={ref} type="checkbox" aria-label={label} className="size-4 accent-brand-600 disabled:opacity-40" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
}

export default function PermissionMatrix({ onNotice }: { onNotice: (m: string | null) => void }) {
  const { t, i18n } = useTranslation('users')
  const locale = i18n.language
  const { can } = useAuth()
  const editable = can('roles.manage')
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['admin-roles', locale], queryFn: usersApi.roles })
  // Edited roles only (role id → working set); anything absent shows the server copy.
  const [draft, setDraft] = useState<Record<number, Set<string>>>({})
  const [error, setError] = useState<string | null>(null)

  const dirty = useMemo(() => {
    if (!q.data) return [] as RoleRow[]
    return q.data.roles.filter((r) => {
      const d = draft[r.id]
      return d && (d.size !== r.permissions.length || r.permissions.some((p) => !d.has(p)))
    })
  }, [q.data, draft])

  const save = useMutation({
    mutationFn: async () => {
      for (const r of dirty) await usersApi.savePermissions(r.id, [...draft[r.id]].sort())
    },
    onSuccess: () => {
      setError(null)
      setDraft({})
      onNotice(t('matrix.saved', { roles: dirty.map((r) => r.label).join(locale === 'ar' ? '، ' : ', ') }))
      void qc.invalidateQueries({ queryKey: ['admin-roles'] })
    },
    onError: (e) => {
      setError(parseApiError(e).message)
      void qc.invalidateQueries({ queryKey: ['admin-roles'] })
    },
  })

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState onRetry={() => void q.refetch()} />

  const { roles, permissions } = q.data
  const groups = Object.entries(permissions)
  const locked = (r: RoleRow) => !editable || r.name === LOCKED
  const current = (r: RoleRow) => draft[r.id] ?? new Set(r.permissions)
  const has = (r: RoleRow, p: string) => r.name === LOCKED || current(r).has(p)
  const setMany = (r: RoleRow, names: string[], on: boolean) =>
    setDraft((d) => {
      const next = new Set(d[r.id] ?? r.permissions)
      names.forEach((n) => (on ? next.add(n) : next.delete(n)))
      return { ...d, [r.id]: next }
    })
  const isDirty = (r: RoleRow) => dirty.some((x) => x.id === r.id)

  return (
    <div className="space-y-4">
      <Notice tone="info">{editable ? t('matrix.intro') : t('matrix.read_only')}</Notice>
      {error && <Notice tone="error">{error}</Notice>}

      <MobileMatrix roles={roles} groups={groups} has={has} setMany={setMany} locked={locked} isDirty={isDirty} dirtyCount={editable ? dirty.length : 0}
        saving={save.isPending} onSave={() => save.mutate()} onDiscard={() => setDraft({})} />
      <section className={`${SURFACE} hidden overflow-hidden lg:block`} aria-label={t('tabs.roles')}>
        <div className="max-h-[70vh] overflow-auto">
          <table className="w-full min-w-[40rem] border-separate border-spacing-0 text-sm">
            <thead>
              <tr>
                <th scope="col" className="sticky start-0 top-0 z-20 border-b border-ink/10 bg-white px-4 py-3 text-start font-semibold text-ink">{t('matrix.permission')}</th>
                {roles.map((r) => (
                  <th key={r.id} scope="col" className="sticky top-0 z-10 min-w-24 border-b border-ink/10 bg-white px-2 py-3 text-center font-semibold text-ink">
                    {r.label}
                    <span className={`block text-xs font-normal tabular-nums ${isDirty(r) ? 'text-gold-700' : 'text-ink/45'}`}>
                      {isDirty(r) ? t('matrix.unsaved') : r.name === LOCKED ? t('matrix.locked') : t('matrix.count', { n: formatNumber(current(r).size, locale) })}
                    </span>
                  </th>
                ))}
              </tr>
            </thead>
            {groups.map(([module, perms]) => (
              <tbody key={module}>
                <tr className="bg-ink/[0.03]">
                  <th scope="rowgroup" className="sticky start-0 z-[5] border-b border-ink/6 bg-page px-4 py-2 text-start text-xs font-semibold uppercase tracking-wide text-ink/60">
                    {t(`modules.${module}`, { defaultValue: module })}
                  </th>
                  {roles.map((r) => {
                    const names = perms.map((p) => p.name)
                    const n = names.filter((p) => has(r, p)).length
                    return (
                      <td key={r.id} className="border-b border-ink/6 px-2 py-2 text-center">
                        <GroupBox checked={n === names.length} indeterminate={n > 0 && n < names.length} disabled={locked(r)}
                          label={t('matrix.group_toggle', { module: t(`modules.${module}`, { defaultValue: module }), role: r.label })}
                          onChange={(on) => setMany(r, names, on)} />
                      </td>
                    )
                  })}
                </tr>
                {perms.map((p) => (
                  <tr key={p.name} className="group">
                    <th scope="row" className="sticky start-0 z-[5] border-b border-ink/5 bg-white px-4 py-2 text-start font-normal text-ink/80 group-hover:bg-brand-50">
                      {p.label}
                      <span dir="ltr" className="block text-start font-mono text-xs text-ink/55">{p.name}</span>
                    </th>
                    {roles.map((r) => (
                      <td key={r.id} className="border-b border-ink/5 px-2 py-2 text-center group-hover:bg-brand-50">
                        <input type="checkbox" className="size-4 accent-brand-600 disabled:opacity-40" aria-label={`${p.label} — ${r.label}`}
                          checked={has(r, p.name)} disabled={locked(r)} onChange={(e) => setMany(r, [p.name], e.target.checked)} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            ))}
          </table>
        </div>
      </section>

      {editable && dirty.length > 0 && (
        <div className="sticky bottom-4 z-30 hidden flex-wrap items-center gap-3 rounded-2xl border border-gold-500/30 bg-white px-4 py-3 shadow-lg lg:flex">
          <p className="flex-1 text-sm text-ink/75">{t('matrix.pending', { roles: dirty.map((r) => r.label).join(locale === 'ar' ? '، ' : ', ') })}</p>
          <SecondaryButton disabled={save.isPending} onClick={() => setDraft({})}>{t('matrix.discard')}</SecondaryButton>
          <PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{t('matrix.save')}</PrimaryButton>
        </div>
      )}
    </div>
  )
}
