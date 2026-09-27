import type { ReactNode } from 'react'
import Icon from '../Icon'
import OrnamentSvg from './OrnamentSvg'

/** Empty state with a medallion illustration. The icon (if any) stays visible when ornaments are off. */
export default function EmptyState({ title, body, icon, size = 'md', children }: { title: string; body?: string; icon?: string; size?: 'sm' | 'md'; children?: ReactNode }) {
  const md = size === 'md'
  return (
    <div className={`flex flex-col items-center text-center ${md ? 'px-6 py-10' : 'px-5 py-8'}`}>
      <span className={`relative grid place-items-center text-brand-600/35 ${md ? 'size-24' : 'size-14'}`}>
        <OrnamentSvg name="medallion" className="ornament absolute inset-0 size-full" />
        {icon && (
          <span className={`relative rounded-full bg-brand-50 text-brand-700 ${md ? 'p-2.5' : 'p-1.5'}`}>
            <Icon name={icon} className={md ? 'size-7' : 'size-5'} />
          </span>
        )}
      </span>
      <p className={`mt-4 ${md ? 'font-semibold text-ink' : 'text-sm text-ink/60'}`}>{title}</p>
      {body && <p className="mt-1 max-w-md text-sm text-ink/60">{body}</p>}
      {children}
    </div>
  )
}
