import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cardErrorCode, readCard, watchCards, type CardData, type CardReaderError } from '../client'
import { useIdCardConfig } from './context'
import { ID_CARD_NS } from './i18n'

function loadAuto(key: string) {
  try { return localStorage.getItem(key) === '1' } catch { return false }
}

/**
 * "Read ID card" for a form: reads the card through the card reader service on this PC and hands the data to
 * the form. With "Automatic" on, an inserted card is read without a click (remembered per browser under
 * `storageKey`). Only the name is shown back; the CPR number never appears in the bar.
 */
export default function CardReaderBar({ onRead, storageKey = 'idCard.auto', className = '' }: {
  onRead: (card: CardData) => void
  storageKey?: string
  className?: string
}) {
  const { t } = useTranslation(ID_CARD_NS)
  const { ui } = useIdCardConfig()
  const [busy, setBusy] = useState(false)
  const [auto, setAuto] = useState(() => loadAuto(storageKey))
  const [linked, setLinked] = useState<'connected' | CardReaderError | null>(null)
  const [status, setStatus] = useState<{ tone: 'ok' | 'warn'; text: string } | null>(null)
  // The latest handler, so the socket (opened once) always fills the current form.
  const onReadRef = useRef(onRead)
  useEffect(() => { onReadRef.current = onRead }, [onRead])

  const done = (card: CardData) => {
    onReadRef.current(card)
    setStatus({ tone: 'ok', text: card.nameAr ?? card.nameEn ? t('read_ok', { name: card.nameAr ?? card.nameEn }) : t('read_ok_no_name') })
  }

  const readNow = async () => {
    setBusy(true); setStatus(null)
    try {
      done(await readCard())
    } catch (e) {
      setStatus({ tone: 'warn', text: t(`error_${cardErrorCode(e)}`) })
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    try { localStorage.setItem(storageKey, auto ? '1' : '0') } catch { /* storage blocked */ }
    if (!auto) { setLinked(null); return }
    return watchCards(done, (s, code) => setLinked(s === 'connected' ? 'connected' : code ?? 'not_running'))
    // done reads the latest form handler through a ref; the socket only depends on the switch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [auto, storageKey])

  const { PrimaryButton, Icon } = ui
  return (
    <div className={`rounded-xl border border-brand-600/15 bg-brand-50/50 p-3 ${className}`}>
      <div className="flex flex-wrap items-center gap-3">
        <PrimaryButton loading={busy} onClick={() => void readNow()}>
          {!busy && Icon && <Icon name="id-card" className="size-4" />}{t('read')}
        </PrimaryButton>
        <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-ink/75">
          <input type="checkbox" className="peer sr-only" checked={auto} onChange={(e) => setAuto(e.target.checked)} />
          <span aria-hidden className="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full bg-ink/20 transition peer-checked:bg-brand-600 peer-focus-visible:outline-2 peer-focus-visible:outline-brand-500 after:absolute after:start-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-5 rtl:peer-checked:after:-translate-x-5" />
          {t('auto')}
        </label>
        {auto && linked && (
          <span className={`inline-flex items-center gap-1.5 text-xs ${linked === 'connected' ? 'text-brand-700' : 'text-ink/50'}`}>
            <span className={`size-2 rounded-full ${linked === 'connected' ? 'bg-brand-600' : 'bg-ink/30'}`} aria-hidden />
            {linked === 'connected' ? t('waiting') : linked === 'reader_off' ? t('reader_not_ready') : t('not_connected')}
          </span>
        )}
      </div>
      <p className="mt-2 text-xs text-ink/55">{t('hint')}</p>
      {status && (
        <p role="status" className={`mt-2 text-sm ${status.tone === 'ok' ? 'text-brand-700' : 'text-gold-700'}`}>{status.text}</p>
      )}
    </div>
  )
}
