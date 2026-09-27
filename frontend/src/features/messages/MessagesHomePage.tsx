import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../app/AuthContext'
import { PageBand } from '../../components/ornaments'
import { Segmented } from '../../components/ui'
import LogTab from './LogTab'
import SendTab from './SendTab'
import TemplatesTab from './TemplatesTab'
import WhatsAppTab from './WhatsAppTab'
import InboxTab from './InboxTab'
import RulesTab from './RulesTab'

type Tab = 'log' | 'inbox' | 'send' | 'templates' | 'rules' | 'whatsapp'

/** Messages: log (+ resend), manual send, templates, WhatsApp connection. Each tab follows its permission. */
export default function MessagesHomePage() {
  const { t } = useTranslation('messages')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()

  const tabs: Tab[] = [
    ...(can('messages.view') ? ['log' as const] : []),
    ...(can('messages.view', 'attendance.record') ? ['inbox' as const] : []),
    ...(can('messages.send') ? ['send' as const] : []),
    ...(can('messages.view', 'messages.manage') ? ['templates' as const] : []),
    ...(can('messages.manage') ? ['rules' as const] : []),
    ...(can('whatsapp.status') ? ['whatsapp' as const] : []),
  ]
  const asked = params.get('tab') as Tab | null
  const tab: Tab | undefined = asked && tabs.includes(asked) ? asked : tabs[0]

  return (
    <div className="space-y-5">
      <PageBand title={t('title')} subtitle={t('subtitle')} />
      {tabs.length > 1 && (
        <Segmented name="messages-tab" label={t('title')} value={tab ?? null}
          options={tabs.map((k) => ({ value: k, label: t(`tabs.${k}`) }))}
          onChange={(v) => setParams({ tab: v }, { replace: true })} />
      )}
      {tab === 'log' && <LogTab />}
      {tab === 'send' && <SendTab />}
      {tab === 'templates' && <TemplatesTab />}
      {tab === 'inbox' && <InboxTab />}
      {tab === 'rules' && <RulesTab />}
      {tab === 'whatsapp' && <WhatsAppTab />}
    </div>
  )
}
