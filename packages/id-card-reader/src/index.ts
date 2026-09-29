// @ahl/id-card-reader: read Bahrain smart ID cards through iGA's GCC CardRead Server. Framework free.
export {
  readCard, watchCards, parseCard, parseDate, parseGender, normalizeCpr, fatherNameFrom, cardErrorCode,
  configureCardReader, cardReaderConfig,
  type CardData, type CardReaderError, type CardReaderConfig,
} from './client'
