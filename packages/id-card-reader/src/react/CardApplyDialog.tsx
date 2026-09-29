import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cardErrorCode, readCard, type CardData } from '../client'
import { useIdCardConfig } from './context'
import { ID_CARD_NS } from './i18n'

/** The record's current values. `address` is optional: leave it out where the host does not keep an address. */
export interface CardFields { full_name: string; birth_date: string; gender: 'male' | 'female' | ''; cpr: string; address?: string }
type Key = keyof CardFields

/**
 * Read the ID card for an existing record (a person already on file) and let staff choose what to
 * take from it. The card's values are shown next to the ones on file; only ticked fields are sent. The card
 * photo is offered separately and is never applied unless staff tick "Use this photo".
 */
export default function CardApplyDialog({ title, current, allowPhoto, onApply, onClose }: {
  title: string
  current: CardFields
  allowPhoto: boolean
  onApply: (patch: Partial<CardFields>, photo: File | null) => Promise<void>
  onClose: () => void
}) {
  const { t, i18n } = useTranslation(ID_CARD_NS)
  const locale = i18n.language
  const { ui, formatDate, parseError } = useIdCardConfig()
  const { Modal, Notice, PrimaryButton, SecondaryButton } = ui
  const hasAddress = current.address !== undefined
  const [card, setCard] = useState<CardData | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [reading, setReading] = useState(true)
  const [picked, setPicked] = useState<Record<Key, boolean>>({ full_name: false, birth_date: false, gender: false, cpr: false, address: false })
  const [usePhoto, setUsePhoto] = useState(false)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)

  const read = async () => {
    setReading(true); setError(null); setCard(null)
    try {
      const c = await readCard()
      setCard(c)
      const v = fromCard(c)
      // Tick every field the card has and that differs from what is on file, unless the card's CPR differs from
      // the one on file: then it may be someone else's card, so nothing is ticked and a warning is shown.
      const otherPerson = !!current.cpr && !!v.cpr && v.cpr !== current.cpr
      if (otherPerson) setPicked({ full_name: false, birth_date: false, gender: false, cpr: false, address: false })
      else setPicked({ full_name: !!v.full_name && v.full_name !== current.full_name, birth_date: !!v.birth_date && v.birth_date !== current.birth_date, gender: !!v.gender && v.gender !== current.gender, cpr: !!v.cpr && v.cpr !== current.cpr, address: !!v.address && hasAddress && v.address !== (current.address ?? '') })
      setUsePhoto(false)
    } catch (e) {
      setError(t(`error_${cardErrorCode(e)}`))
    } finally {
      setReading(false)
    }
  }
  // Read once when the dialog opens (the card is expected to be in the reader already).
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { void read() }, [])

  const values = card ? fromCard(card) : null
  const otherPerson = !!values && !!current.cpr && !!values.cpr && values.cpr !== current.cpr
  const photoUrl = useMemo(() => (card?.photo ? URL.createObjectURL(card.photo) : null), [card])
  useEffect(() => () => void (photoUrl && URL.revokeObjectURL(photoUrl)), [photoUrl])

  const show = (k: Key, v: string) => {
    if (!v) return <span className="text-ink/40">—</span>
    if (k === 'birth_date') return <span className="tabular-nums">{formatDate(v, locale)}</span>
    if (k === 'gender') return <span>{t(`gender_${v}`)}</span>
    if (k === 'cpr') return <span dir="ltr" className="tabular-nums">{v}</span>
    return <span dir="auto">{v}</span>
  }
  const rows: { key: Key; label: string }[] = [
    { key: 'full_name', label: t('fields.full_name') },
    { key: 'birth_date', label: t('fields.birth_date') },
    { key: 'gender', label: t('fields.gender') },
    { key: 'cpr', label: t('fields.cpr') },
    ...(hasAddress ? [{ key: 'address' as Key, label: t('fields.address') }] : []),
  ]
  const chosen = rows.filter((r) => picked[r.key] && values?.[r.key])
  const nothing = chosen.length === 0 && !(usePhoto && card?.photo)

  const apply = async () => {
    if (!values) return
    setSaving(true); setSaveError(null)
    try {
      await onApply(Object.fromEntries(chosen.map((r) => [r.key, values[r.key]])) as Partial<CardFields>, usePhoto && card?.photo ? card.photo : null)
    } catch (e) {
      setSaveError(parseError(e).message)
      setSaving(false)
    }
  }

  return (
    <Modal wide title={title} onClose={onClose}
      footer={<>
        <SecondaryButton onClick={onClose}>{t('cancel')}</SecondaryButton>
        {!reading && <SecondaryButton onClick={() => void read()}>{t('read_again')}</SecondaryButton>}
        <PrimaryButton loading={saving} disabled={!values || nothing} onClick={() => void apply()}>{t('apply')}</PrimaryButton>
      </>}>
      {reading && <p role="status" className="text-sm text-ink/60">{t('reading')}</p>}
      {error && <Notice tone="error">{error}</Notice>}
      {saveError && <Notice tone="error">{saveError}</Notice>}
      {values && (
        <>
          {otherPerson && <Notice tone="error">{t('other_person')}</Notice>}
          <p className="text-sm text-ink/60">{t('apply_hint')}</p>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[28rem] text-sm">
              <thead>
                <tr className="border-b border-ink/10 text-xs text-ink/55">
                  <th className="w-10 py-2" />
                  <th className="py-2 text-start font-medium">{t('field')}</th>
                  <th className="py-2 text-start font-medium">{t('on_file')}</th>
                  <th className="py-2 text-start font-medium">{t('on_card')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => {
                  const cardV = values[r.key] ?? '', fileV = current[r.key] ?? ''
                  const same = !cardV || cardV === fileV
                  return (
                    <tr key={r.key} className="border-b border-ink/5 last:border-0">
                      <td className="py-2.5">
                        <input type="checkbox" className="size-4 accent-brand-700" aria-label={r.label} disabled={same}
                          checked={!same && picked[r.key]} onChange={(e) => setPicked((p) => ({ ...p, [r.key]: e.target.checked }))} />
                      </td>
                      <td className="py-2.5 text-ink/70">{r.label}</td>
                      <td className="py-2.5 text-ink/80">{show(r.key, fileV)}</td>
                      <td className={`py-2.5 ${same ? 'text-ink/50' : 'font-medium text-ink'}`}>
                        {show(r.key, cardV)}{same && cardV && <span className="ms-2 text-xs text-ink/40">{t('same')}</span>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          {allowPhoto && (
            photoUrl ? (
              <label className="flex cursor-pointer items-center gap-3 rounded-xl border border-ink/10 p-3">
                <input type="checkbox" className="size-4 accent-brand-700" checked={usePhoto} onChange={(e) => setUsePhoto(e.target.checked)} />
                <img src={photoUrl} alt="" className="size-16 rounded-lg object-cover ring-1 ring-ink/10" />
                <span className="text-sm text-ink/80">{t('use_photo')}<span className="block text-xs text-ink/50">{t('use_photo_hint')}</span></span>
              </label>
            ) : <p className="text-xs text-ink/50">{t('no_photo')}</p>
          )}
        </>
      )}
    </Modal>
  )
}

function fromCard(c: CardData): CardFields {
  return { full_name: c.nameAr ?? c.nameEn ?? '', birth_date: c.birthDate ?? '', gender: c.gender ?? '', cpr: c.cpr ?? '', address: c.addressAr ?? c.addressEn ?? '' }
}
