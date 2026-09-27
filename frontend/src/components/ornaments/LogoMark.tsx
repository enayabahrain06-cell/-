import OrnamentSvg from './OrnamentSvg'

/** App logo: the khatam mark in gold on the deep emerald tile. Brand, not decoration, so ornament_level never hides it. */
export default function LogoMark({ className = 'size-10' }: { className?: string }) {
  return (
    <span aria-hidden className={`inline-grid shrink-0 place-items-center rounded-[28%] bg-deep text-gold-400 ring-1 ring-gold-300/30 ${className}`}>
      <OrnamentSvg name="logoMark" className="size-[86%]" />
    </span>
  )
}
