/**
 * Girih pattern layer for header bands. Put it first inside a `relative overflow-hidden` parent and
 * lift the parent's content above it with `relative`. Never place it behind body text or data.
 */
export default function OrnamentPattern({ className = 'text-gold-300' }: { className?: string }) {
  return <span aria-hidden className={`ornament ornament-pattern ${className}`} />
}

/** Thin girih strip: the only ornament allowed on data-dense screens (tables, forms, exams), as a top border. */
export function OrnamentStrip({ className = 'text-gold-500' }: { className?: string }) {
  return (
    <span aria-hidden className={`ornament relative block h-2 overflow-hidden ${className}`}>
      <span className="ornament-pattern ornament-size-16 ornament-opacity-60" />
    </span>
  )
}
