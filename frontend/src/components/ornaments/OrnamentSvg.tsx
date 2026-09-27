import khatam from '../../assets/ornaments/khatam.svg?raw'
import medallion from '../../assets/ornaments/medallion.svg?raw'
import logoMark from '../../assets/ornaments/logo-mark.svg?raw'
import divider from '../../assets/ornaments/divider.svg?raw'

/** The SVG files in assets/ornaments are the single source; they are inlined so currentColor follows the theme. */
const SOURCES = { khatam, medallion, logoMark, divider }

export type OrnamentName = keyof typeof SOURCES

export default function OrnamentSvg({ name, className = '' }: { name: OrnamentName; className?: string }) {
  // Trusted, bundled assets only: never pass user content here.
  return <span aria-hidden className={`ornament-svg ${className}`} dangerouslySetInnerHTML={{ __html: SOURCES[name] }} />
}
