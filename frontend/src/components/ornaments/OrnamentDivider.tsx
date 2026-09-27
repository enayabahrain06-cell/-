import OrnamentSvg from './OrnamentSvg'

/** Ornamental rule under page titles: knots and a small khatam, trailing into a fading line. */
export default function OrnamentDivider({ className = 'text-gold-500', align = 'start' }: { className?: string; align?: 'start' | 'center' }) {
  return (
    <span aria-hidden className={`ornament flex items-center gap-2 ${align === 'center' ? 'justify-center' : ''} ${className}`}>
      {align === 'center' && <span className="h-px w-16 bg-linear-to-l from-current to-transparent opacity-70 rtl:bg-linear-to-r" />}
      <OrnamentSvg name="divider" className="h-3 w-24" />
      <span className="h-px w-16 bg-linear-to-r from-current to-transparent opacity-70 rtl:bg-linear-to-l" />
    </span>
  )
}
