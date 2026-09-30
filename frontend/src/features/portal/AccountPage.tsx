import { useCallback, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { authApi } from '../../api/auth'
import { parseApiError, tokenStore } from '../../api/client'
import type { PortalCard } from '../../api/portal'
import { studentsApi } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { MAvatar, MSection, MSegmented } from '../../components/mobile/atoms'
import MobileToast from '../../components/mobile/Toast'
import { setLocale, type AppLocale } from '../../lib/i18n'
import PortalLayout from './PortalLayout'
import { usePortalOverview, usePortalRole } from './hooks'
import { CardSkeleton } from './shared'

/** حسابي (/my-account): profile, language, the guardian's photo change per child, further pages, logout. */
export default function AccountPage() {
  const { t, i18n } = useTranslation('portal')
  const { user, signOut } = useAuth()
  const role = usePortalRole()
  const overview = usePortalOverview()
  const cards = overview.data?.students ?? []
  const [toast, setToast] = useState<{ text: string; tone: 'ok' | 'error' } | null>(null)
  const clearToast = useCallback(() => setToast(null), [])

  const switchTo = (next: AppLocale) => {
    if (next === i18n.language) return
    setLocale(next)
    if (tokenStore.get()) void authApi.updateLocale(next).catch(() => undefined)
  }

  const links = role === 'guardian'
    ? [
        { to: '/my-schedule', icon: 'clock', key: 'schedule' },
        { to: '/my/exams', icon: 'exams', key: 'exams' },
        { to: '/my/honor', icon: 'trophy', key: 'honor' },
      ]
    : [
        { to: '/my-progress/memorization', icon: 'evaluation', key: 'memorization' },
        { to: '/my-progress/certificates', icon: 'certificate', key: 'certificates' },
        { to: '/my-invoices', icon: 'wallet', key: 'invoices' },
        { to: '/my-messages', icon: 'messages', key: 'messages' },
        { to: '/my/exams', icon: 'exams', key: 'exams' },
      ]

  return (
    <PortalLayout>
      <MobileToast message={toast?.text ?? null} tone={toast?.tone} onDone={clearToast} />
      <div className="mx-auto max-w-2xl space-y-6">
        <h1 className="font-display text-[22px] leading-7 text-brand-900 lg:text-3xl">{t('account.title')}</h1>
        {toast && <p role="status" className={`hidden rounded-ctl px-3 py-2 text-sm lg:block ${toast.tone === 'error' ? 'bg-danger/10 text-danger' : 'bg-brand-50 text-brand-800'}`}>{toast.text}</p>}

        <div className="flex items-center gap-3 rounded-card border border-ink/10 bg-white p-4 shadow-card">
          <MAvatar name={user?.name ?? '?'} size={52} />
          <div className="min-w-0">
            <p className="truncate text-[15px] font-semibold text-ink"><bdi>{user?.name}</bdi></p>
            <p className="truncate text-[13px] text-ink/65">{t(`account.role_${role}`)} · <span dir="ltr">{user?.phone}</span></p>
          </div>
        </div>

        <MSection title={t('account.language')}>
          <MSegmented<AppLocale> label={t('account.language')} value={i18n.language as AppLocale} onChange={switchTo}
            options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]} />
        </MSection>

        {role === 'guardian' && (
          <MSection title={t('account.photos')}>
            <p className="-mt-1 text-[13px] text-ink/65">{t('account.photos_hint')}</p>
            {overview.isLoading ? <CardSkeleton lines={1} /> : (
              <ul className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">
                {cards.map((c) => <PhotoRow key={c.student.id} card={c} onDone={setToast} />)}
              </ul>
            )}
          </MSection>
        )}

        <MSection title={t('account.more')}>
          <ul className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">
            {links.map((l) => (
              <li key={l.key}>
                <Link to={l.to} className="flex min-h-[52px] items-center gap-3 px-4 py-2 active:bg-brand-50/60">
                  <span className="inline-grid size-8 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700"><Icon name={l.icon} className="size-[18px]" /></span>
                  <span className="min-w-0 flex-1 truncate text-[15px] text-ink">{t(`links.${l.key}`)}</span>
                  <Icon name="chevron" className="size-4 shrink-0 text-ink/40 rtl:rotate-180" />
                </Link>
              </li>
            ))}
          </ul>
        </MSection>

        <button type="button" onClick={() => void signOut()}
          className="flex min-h-[52px] w-full items-center gap-3 rounded-card border border-ink/10 bg-white px-4 text-[15px] font-semibold text-danger shadow-card">
          <Icon name="logout" className="size-5" />{t('common:logout')}
        </button>
      </div>
    </PortalLayout>
  )
}

/** One child with a change-photo button (StudentPhotoPolicy lets the guardian update their own child's photo). */
function PhotoRow({ card, onDone }: { card: PortalCard; onDone: (m: { text: string; tone: 'ok' | 'error' }) => void }) {
  const { t } = useTranslation('portal')
  const qc = useQueryClient()
  const input = useRef<HTMLInputElement>(null)
  const s = card.student
  const upload = useMutation({
    mutationFn: (file: File) => studentsApi.uploadPhoto(s.id, file),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['portal-overview'] })
      onDone({ text: t('account.photo_saved'), tone: 'ok' })
    },
    onError: (e) => onDone({ text: parseApiError(e).message, tone: 'error' }),
  })

  return (
    <li className="flex min-h-16 items-center gap-3 px-4 py-2.5">
      <MAvatar name={s.full_name} src={s.photo_url} size={44} />
      <span className="min-w-0 flex-1 truncate text-[15px] font-semibold text-ink"><bdi>{s.full_name}</bdi></span>
      <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" tabIndex={-1} aria-hidden
        onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f); e.target.value = '' }} />
      <button type="button" onClick={() => input.current?.click()} disabled={upload.isPending}
        className="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-ctl border border-ink/10 bg-white px-3 text-[13px] font-semibold text-brand-700 disabled:opacity-60">
        <Icon name="camera" className="size-4" />{upload.isPending ? t('account.uploading') : s.has_photo ? t('account.change_photo') : t('account.add_photo')}
      </button>
    </li>
  )
}
