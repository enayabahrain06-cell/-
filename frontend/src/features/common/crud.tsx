import { useState, type ReactNode } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import { Notice, PrimaryButton, SecondaryButton } from '../../components/ui'

/** Shared pieces of the small list-and-dialog screens (master data, term setup). */
export type CrudNotice = { tone: 'success' | 'error'; text: string } | null

/** Delete with confirmation; the server's refusal (in use, current, system) comes back as an error notice. */
// eslint-disable-next-line react-refresh/only-export-components
export function useRemove(fn: (id: number) => Promise<{ message: string }>, keys: string[][], confirmText: string) {
  const qc = useQueryClient()
  const [notice, setNotice] = useState<CrudNotice>(null)
  const m = useMutation({
    mutationFn: fn,
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); keys.forEach((k) => void qc.invalidateQueries({ queryKey: k })) },
    onError: (e) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })
  return { notice, setNotice, remove: (id: number) => { if (window.confirm(confirmText)) m.mutate(id) } }
}

/** The add button on the end side, then the last action's notice. */
export function Toolbar({ label, onAdd, notice, children }: { label?: string; onAdd?: () => void; notice: CrudNotice; children?: ReactNode }) {
  return (
    <>
      {(onAdd || children) && (
        <div className="flex flex-wrap items-end justify-end gap-2">
          {children}
          {onAdd && label && <PrimaryButton onClick={onAdd}><Icon name="plus" className="size-4" />{label}</PrimaryButton>}
        </div>
      )}
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
    </>
  )
}

export function ItemActions({ onEdit, onDelete, canDelete = true, children }: { onEdit?: () => void; onDelete?: () => void; canDelete?: boolean; children?: ReactNode }) {
  const { t } = useTranslation('common')
  return (
    <div className="mt-3 flex flex-wrap gap-2">
      {onEdit && <SecondaryButton onClick={onEdit}><Icon name="edit" className="size-4" />{t('crud.edit')}</SecondaryButton>}
      {children}
      {canDelete && onDelete && <SecondaryButton onClick={onDelete} className="text-danger"><Icon name="trash" className="size-4" />{t('crud.delete')}</SecondaryButton>}
    </div>
  )
}

/** A form control with its server error under it. */
export function Field({ error, children, className = '' }: { error?: string; children: ReactNode; className?: string }) {
  return (
    <div className={className}>
      {children}
      {error && <p className="mt-1 text-sm text-danger">{error}</p>}
    </div>
  )
}

/** Cancel / save footer for a Modal. */
export function DialogFooter({ onCancel, onSave, saving }: { onCancel: () => void; onSave: () => void; saving: boolean }) {
  const { t } = useTranslation('common')
  return <><SecondaryButton onClick={onCancel}>{t('crud.cancel')}</SecondaryButton><PrimaryButton loading={saving} onClick={onSave}>{t('crud.save')}</PrimaryButton></>
}
