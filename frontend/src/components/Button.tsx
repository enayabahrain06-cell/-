import type { ButtonHTMLAttributes } from 'react'
import Khatam from './ornaments/Khatam'

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
  loading?: boolean
  variant?: 'primary' | 'ghost'
}

export default function Button({ loading, variant = 'primary', className = '', children, disabled, ...rest }: Props) {
  const base =
    'inline-flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-base font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:cursor-not-allowed disabled:opacity-60'
  const styles =
    variant === 'primary'
      ? 'bg-brand-700 text-white shadow-sm hover:bg-brand-800 active:bg-brand-900'
      : 'text-brand-700 hover:bg-brand-50'
  return (
    <button className={`${base} ${styles} ${className}`} disabled={disabled || loading} aria-busy={loading} {...rest}>
      {loading && <Khatam className="star-spin size-5" />}
      {children}
    </button>
  )
}
