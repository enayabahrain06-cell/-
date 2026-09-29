import { CardReaderBar } from '@ahl/id-card-reader/react'
import type { CardData } from '../../lib/cardReader'

/** The package's "Read ID card" bar, laid out for the enrollment form grid. */
export default function EnrollmentCardReaderBar({ onRead }: { onRead: (card: CardData) => void }) {
  return <CardReaderBar onRead={onRead} storageKey="ahl.cardReader.auto" className="sm:col-span-2" />
}
