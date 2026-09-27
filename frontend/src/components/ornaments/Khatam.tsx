import OrnamentSvg from './OrnamentSvg'

/** Eight-pointed star (two squares, one rotated 45°). Stroke in currentColor, inner ring in --ornament-accent. */
export default function Khatam({ className = 'size-6' }: { className?: string }) {
  return <OrnamentSvg name="khatam" className={className} />
}
