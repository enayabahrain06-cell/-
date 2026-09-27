import type { ReactNode } from 'react'

export default function Alert({ tone = 'error', children }: { tone?: 'error' | 'info' | 'success'; children: ReactNode }) {
  const styles = {
    error: 'border-red-200 bg-red-50 text-red-800',
    info: 'border-sky-200 bg-sky-50 text-sky-900',
    success: 'border-brand-100 bg-brand-50 text-brand-900',
  }[tone]
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-xl border px-4 py-3 text-sm ${styles}`}>
      {children}
    </div>
  )
}
