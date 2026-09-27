import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { messagesApi } from '../../api/messages'
import Icon from '../../components/Icon'
import { Badge, Card, CardTitle, ErrorState, LoadingState, Notice, SecondaryButton } from '../../components/ui'
import { formatDate } from '../../lib/format'

/** WhatsApp connection: provider, live status (polled every 10 s while open) and the QR code to link a device. */
export default function WhatsAppTab() {
  const { t, i18n } = useTranslation('messages')
  const locale = i18n.language
  const status = useQuery({ queryKey: ['whatsapp-status'], queryFn: messagesApi.whatsappStatus, refetchInterval: 10_000 })
  const needsQr = status.data?.provider === 'openwa' && !status.data.connected
  const qr = useQuery({ queryKey: ['whatsapp-qr'], queryFn: messagesApi.whatsappQr, enabled: needsQr, refetchInterval: needsQr ? 10_000 : false })

  if (status.isLoading) return <LoadingState />
  if (status.isError || !status.data) return <ErrorState message={t('whatsapp.error')} onRetry={() => void status.refetch()} />
  const s = status.data
  const qrSrc = qr.data ? (qr.data.startsWith('data:') ? qr.data : `data:image/png;base64,${qr.data}`) : null

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card>
        <CardTitle actions={<SecondaryButton onClick={() => void status.refetch()} disabled={status.isFetching}><Icon name="refresh" className="size-4" /> {t('whatsapp.refresh')}</SecondaryButton>}>
          {t('whatsapp.title')}
        </CardTitle>
        <dl className="space-y-3 text-sm">
          <div className="flex items-center justify-between gap-2"><dt className="text-ink/60">{t('whatsapp.provider')}</dt><dd dir="ltr" className="font-medium text-ink">{s.provider}</dd></div>
          <div className="flex items-center justify-between gap-2">
            <dt className="text-ink/60">{t('whatsapp.status')}</dt>
            <dd>{s.connected ? <Badge tone="brand"><Icon name="check" className="size-3.5" /> {t('whatsapp.connected')}</Badge> : <Badge tone="danger">{needsQr ? t('whatsapp.needs_scan') : t('whatsapp.disconnected')}</Badge>}</dd>
          </div>
          {s.detail && <div className="flex items-start justify-between gap-2"><dt className="text-ink/60">{t('whatsapp.detail')}</dt><dd dir="auto" className="text-end text-ink/80">{s.detail}</dd></div>}
        </dl>
        <p className="mt-4 text-xs text-ink/50">
          {t('whatsapp.checked', { time: formatDate(new Date(status.dataUpdatedAt), locale, { hour: 'numeric', minute: '2-digit', second: '2-digit' }) })} · {t('whatsapp.auto')}
        </p>
        {s.provider === 'log' && <div className="mt-4"><Notice tone="info">{t('whatsapp.log_provider')}</Notice></div>}
      </Card>
      <Card>
        <CardTitle>{t('whatsapp.qr_title')}</CardTitle>
        {!needsQr ? <p className="text-sm text-ink/60">{s.connected ? t('whatsapp.connected') : t('whatsapp.no_qr')}</p>
          : qr.isLoading ? <LoadingState />
            : qrSrc ? (
              <div className="space-y-3 text-center">
                <img src={qrSrc} alt={t('whatsapp.qr_title')} className="mx-auto size-64 rounded-xl border border-ink/8 bg-white p-2" />
                <p className="text-sm text-ink/70">{t('whatsapp.qr_steps')}</p>
              </div>
            ) : <p className="text-sm text-ink/60">{t('whatsapp.no_qr')}</p>}
      </Card>
    </div>
  )
}
