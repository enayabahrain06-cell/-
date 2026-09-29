import type { ReactNode } from 'react'
import { configureCardReader } from '@ahl/id-card-reader'
import { IdCardProvider, registerIdCardI18n, type UiKit } from '@ahl/id-card-reader/react'
import { parseApiError } from '../api/client'
import Icon from '../components/Icon'
import { Modal, Notice, PrimaryButton, SecondaryButton } from '../components/ui'
import { formatDate } from '../lib/format'
import i18n from '../lib/i18n'

// The card reader service's endpoints per deployment (e.g. its https / wss endpoints on an https site).
configureCardReader({ restUrl: import.meta.env.VITE_CARD_READER_URL, wsUrl: import.meta.env.VITE_CARD_READER_WS })

// Package texts under the app's own locales/*/idCard.json (the app keys win: student wording, Boy/Girl).
registerIdCardI18n(i18n)

/** The ID card package drawn with this app's design system (components/ui.tsx). */
const UI: Partial<UiKit> = {
  PrimaryButton, SecondaryButton, Modal, Notice,
  Icon: ({ className }) => <Icon name="students" className={className} />,
}

export function AppIdCardProvider({ children }: { children: ReactNode }) {
  return (
    <IdCardProvider ui={UI} formatDate={(v, locale) => formatDate(v, locale)} parseError={parseApiError}>
      {children}
    </IdCardProvider>
  )
}
