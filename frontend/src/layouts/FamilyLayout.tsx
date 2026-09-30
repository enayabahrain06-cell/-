import type { ReactNode } from 'react'
import PortalLayout from '../features/portal/PortalLayout'

/** Student / guardian pages built before the portal (exams, honor board) share the portal shell and its bottom nav. */
export default function FamilyLayout({ children }: { children: ReactNode }) {
  return <PortalLayout>{children}</PortalLayout>
}
