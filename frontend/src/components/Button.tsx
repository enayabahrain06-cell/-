import type { ButtonHTMLAttributes } from 'react'

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
  loading?: boolean
  variant?: 'primary' | 'ghost'
}

export default function Button({ loading, variant = 'primary', className = '', children, disabled, ...rest }: Props) {
  const base =
    'inline-flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-base font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:cursor-not-allowed disabled:opacity-60'
  const styles =
    variant === 'primary'
      ? 'bg-brand-700 text-white shadow-sm hover:bg-brand-600 active:bg-brand-900'
      : 'text-brand-700 hover:bg-brand-50'
  return (
    <button className={`${base} ${styles} ${className}`} disabled={disabled || loading} aria-busy={loading} {...rest}>
      {loading && (
        <svg aria-hidden viewBox="0 0 24 24" className="size-5 animate-spin" fill="none">
          <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity=".25" strokeWidth="3" />
          <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        </svg>
      )}
      {children}
    </button>
  )
}
