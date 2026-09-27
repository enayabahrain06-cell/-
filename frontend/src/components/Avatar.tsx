import { useState } from 'react'

const SIZES = { sm: 'size-9 text-sm', md: 'size-12 text-lg', lg: 'size-24 text-4xl' } as const

/**
 * Student photo from a 10-minute signed URL, or the initial-letter fallback.
 * The API returns photo_url = null when there is no photo or the viewer may not see it (gender rule),
 * so the fallback also covers "hidden" photos without revealing that one exists.
 */
export default function Avatar({ name, initial, src, gender, size = 'md' }: { name: string; initial?: string; src?: string | null; gender?: 'male' | 'female' | null; size?: keyof typeof SIZES }) {
  const [failed, setFailed] = useState(false)
  const letter = initial || name.trim().charAt(0)
  const tone = gender === 'female' ? 'bg-gold-500/15 text-gold-700' : 'bg-brand-50 text-brand-700'

  if (src && !failed) {
    return <img src={src} alt={name} onError={() => setFailed(true)} className={`${SIZES[size]} shrink-0 rounded-full object-cover ring-2 ring-white`} loading="lazy" />
  }

  return (
    <span aria-hidden className={`${SIZES[size]} ${tone} inline-grid shrink-0 place-items-center rounded-full font-display font-bold ring-2 ring-white`}>
      {letter}
    </span>
  )
}
