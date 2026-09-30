import { useId, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { saveBlob } from '../../api/payments'
import Icon from '../../components/Icon'
import { Badge, Notice, SecondaryButton } from '../../components/ui'

/**
 * The first step of an Excel import: download the template, then choose a file that the caller previews.
 * The preview table and the commit belong to the caller (each import has its own columns).
 */
export function SheetPicker({ template, templateName, onFile, disabled = false }: {
  template: () => Promise<Blob>
  templateName: string
  onFile: (file: File) => Promise<void>
  disabled?: boolean
}) {
  const { t } = useTranslation('common')
  const id = useId()
  const ref = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const pick = async (file: File | undefined) => {
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      await onFile(file)
    } catch (e) {
      const p = parseApiError(e)
      setError(p.fields.file?.[0] ?? p.message)
    } finally {
      setBusy(false)
      if (ref.current) ref.current.value = ''
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        <SecondaryButton onClick={async () => { try { saveBlob(await template(), templateName) } catch (e) { setError(parseApiError(e).message) } }}>
          <Icon name="download" className="size-4" />{t('sheet_import.template')}
        </SecondaryButton>
        <input ref={ref} id={id} type="file" accept=".xlsx,.xls,.csv" className="sr-only" disabled={disabled || busy} onChange={(e) => void pick(e.target.files?.[0])} />
        <label htmlFor={id} className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-xl bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-800 has-[:focus-visible]:outline-2">
          <Icon name="table" className="size-4" />{busy ? t('sheet_import.reading') : t('sheet_import.choose')}
        </label>
      </div>
      {error && <Notice tone="error">{error}</Notice>}
    </div>
  )
}

/** Counts shown above a preview table. */
export function PreviewCounts({ valid, invalid, children }: { valid: number; invalid: number; children?: ReactNode }) {
  const { t } = useTranslation('common')
  return (
    <div className="flex flex-wrap items-center gap-2">
      <h2 className="font-semibold text-ink">{t('sheet_import.preview')}</h2>
      <Badge tone="brand">{t('sheet_import.valid', { count: valid })}</Badge>
      <Badge tone="danger">{t('sheet_import.invalid', { count: invalid })}</Badge>
      {children}
    </div>
  )
}
