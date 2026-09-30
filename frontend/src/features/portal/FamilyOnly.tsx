import type { ReactNode } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'

/** Portal pages are for students and guardians; staff (dashboard.view) land on their dashboard instead. */
export default function FamilyOnly({ children }: { children: ReactNode }) {
  const { can } = useAuth()
  return can('dashboard.view') ? <Navigate to="/" replace /> : <>{children}</>
}
