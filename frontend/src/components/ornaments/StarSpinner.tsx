import Khatam from './Khatam'

/** Loading indicator: a slowly rotating khatam. Static under prefers-reduced-motion; the label carries the meaning. */
export default function StarSpinner({ className = 'size-8 text-brand-600', label }: { className?: string; label?: string }) {
  return (
    <span role={label ? 'status' : undefined} className="inline-flex items-center gap-3">
      <Khatam className={`star-spin ${className}`} />
      {label && <span>{label}</span>}
    </span>
  )
}
