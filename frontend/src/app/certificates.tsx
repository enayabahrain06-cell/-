import { useCallback, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { CertificatesProvider, registerCertificatesI18n, type Recipient, type UiKit } from '@ahl/certificates-react'
import { api, parseApiError } from '../api/client'
import type { StudentSummary } from '../api/students'
import Avatar from '../components/Avatar'
import Icon from '../components/Icon'
import Pagination from '../components/Pagination'
import SelectField from '../components/SelectField'
import StudentPicker from '../components/StudentPicker'
import { OrnamentDivider, PageBand, StarSpinner } from '../components/ornaments'
import {
  Badge, Card, EmptyCard, ErrorState, FilterBar, LoadingState, Modal, Notice, PrimaryButton, SearchInput, SecondaryButton,
  Segmented, TableWrap, TextArea, TextInput, SURFACE, TABLE_HEAD, buttonClass,
} from '../components/ui'
import PublicLayout from '../layouts/PublicLayout'
import { formatDate, formatNumber } from '../lib/format'
import i18n from '../lib/i18n'
import { useAuth } from './AuthContext'

// Package texts under the app's own locales/*/certificates.json (the app keys win: student wording).
registerCertificatesI18n(i18n)

/** The certificates package drawn with this app's design system (components/ui.tsx and ornaments). */
const UI: Partial<UiKit> = {
  Badge, PrimaryButton, SecondaryButton, TextInput, TextArea, SelectField, SearchInput, Segmented, Notice,
  LoadingState, ErrorState, EmptyCard, Card, FilterBar, TableWrap, Pagination, Modal, PublicLayout, Icon,
  PageHeader: PageBand,
  Spinner: StarSpinner,
  Divider: OrnamentDivider,
  classes: { surface: SURFACE, tableHead: TABLE_HEAD, headerAction: buttonClass('onDeep') },
}

/** Every recipient here is a student: the API sends the student card merged into the recipient. */
const asStudent = (r: Recipient) => r as unknown as StudentSummary & Recipient

function StudentCard({ recipient, size = 'md' }: { recipient: Recipient; size?: 'sm' | 'md' }) {
  const s = asStudent(recipient)
  if (size === 'sm') {
    return (
      <span className="inline-flex items-center gap-2">
        <Avatar name={s.full_name ?? s.name} initial={s.initial} src={s.photo_url} gender={s.gender} size="sm" />
        <span dir="auto" className="text-ink">{s.full_name ?? s.name}</span>
        <span className="text-xs tabular-nums text-ink/50">{s.student_no}</span>
      </span>
    )
  }
  return (
    <span className="flex min-w-0 items-center gap-2.5">
      <Avatar name={s.full_name ?? s.name} initial={s.initial} src={s.photo_url} gender={s.gender} size="sm" />
      <span className="min-w-0">
        <span dir="auto" className="block truncate font-medium text-ink">{s.full_name ?? s.name}</span>
        <span className="block text-xs tabular-nums text-ink/50">{s.student_no}</span>
      </span>
    </span>
  )
}

function PickStudent({ label, onPick }: { label: string; onPick: (r: Recipient) => void }) {
  return <StudentPicker value={null} label={label} onChange={(s) => s && onPick({ ...s, type: 'student', name: s.full_name })} />
}

export function AppCertificatesProvider({ children }: { children: ReactNode }) {
  const { can } = useAuth()
  const qc = useQueryClient()
  const allowed = useCallback((permission: string) => can(permission), [can])
  const href = useCallback((r: Pick<Recipient, 'id' | 'type'>) => (r.type === 'student' && can('students.view') ? `/students/${r.id}?tab=certificates` : null), [can])
  // The profile header counts approved certificates.
  const onChanged = useCallback(() => void qc.invalidateQueries({ queryKey: ['student-profile'] }), [qc])

  return (
    <CertificatesProvider http={api} ui={UI} can={allowed} RecipientPicker={PickStudent} RecipientCard={StudentCard}
      recipientHref={href} parseError={parseApiError} formatDate={formatDate} formatNumber={formatNumber} onChanged={onChanged}>
      {children}
    </CertificatesProvider>
  )
}
