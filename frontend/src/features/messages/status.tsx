import { useTranslation } from 'react-i18next'
import type { MessageStatus } from '../../api/messages'
import Icon from '../../components/Icon'
import { Badge, type Tone } from '../../components/ui'

/** Status → tone + icon; the label always travels with the colour (never colour alone). */
export const STATUS_META: Record<MessageStatus, { tone: Tone; icon: string }> = {
  scheduled: { tone: 'info', icon: 'clock' },
  queued: { tone: 'info', icon: 'clock' },
  sent: { tone: 'brand', icon: 'check' },
  delivered: { tone: 'brand', icon: 'check' },
  read: { tone: 'brand', icon: 'eye' },
  failed: { tone: 'danger', icon: 'alert' },
  cancelled: { tone: 'muted', icon: 'close' },
  skipped: { tone: 'muted', icon: 'chevron' },
  suppressed: { tone: 'gold', icon: 'ban' },
}

export function StatusBadge({ status }: { status: string }) {
  const { t } = useTranslation('messages')
  const meta = STATUS_META[status as MessageStatus] ?? { tone: 'muted' as Tone, icon: 'alert' }
  return (
    <Badge tone={meta.tone}>
      <Icon name={meta.icon} className="size-3.5" />
      {t(`status.${status}`, { defaultValue: status })}
    </Badge>
  )
}

/** Local datetime with the viewer's digits. */
export function formatDateTime(iso: string, locale: string): string {
  return new Date(iso).toLocaleString(locale === 'ar' ? 'ar-BH' : 'en-BH', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
}
