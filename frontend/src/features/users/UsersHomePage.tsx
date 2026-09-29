import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../app/AuthContext'
import { PageBand } from '../../components/ornaments'
import { buttonClass, Notice, Segmented } from '../../components/ui'
import type { AdminUser } from '../../api/users'
import PermissionMatrix from './PermissionMatrix'
import UserDialog from './UserDialog'
import UsersList from './UsersList'

type Tab = 'users' | 'roles'

export default function UsersHomePage() {
  const { t } = useTranslation('users')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const tabs: Tab[] = [...(can('users.view') ? ['users' as const] : []), ...(can('users.view', 'roles.manage') ? ['roles' as const] : [])]
  const tab = (tabs as string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : tabs[0]
  // undefined = closed, null = new user, AdminUser = editing
  const [editing, setEditing] = useState<AdminUser | null | undefined>(undefined)
  const [notice, setNotice] = useState<string | null>(null)

  return (
    <div className="space-y-5">
      <PageBand
        title={t('title')}
        subtitle={t('subtitle')}
        actions={
          can('users.manage') && tab === 'users' ? (
            <button type="button" onClick={() => setEditing(null)} className={buttonClass('onDeep')}>
              + {t('new_user')}
            </button>
          ) : undefined
        }
      />
      {tabs.length > 1 && (
        <Segmented name="users-tab" label={t('title')} value={tab} options={tabs.map((k) => ({ value: k, label: t(`tabs.${k}`) }))} onChange={(v) => { setNotice(null); setParams({ tab: v }, { replace: true }) }} />
      )}
      {notice && <Notice>{notice}</Notice>}
      {tab === 'users' && <UsersList onEdit={setEditing} onNotice={setNotice} />}
      {tab === 'roles' && <PermissionMatrix onNotice={setNotice} />}
      {editing !== undefined && (
        <UserDialog user={editing} onClose={() => setEditing(undefined)} onSaved={(msg) => { setEditing(undefined); setNotice(msg) }} />
      )}
    </div>
  )
}
