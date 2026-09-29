// @ahl/id-card-reader/react: the read button and the compare-and-apply dialog.
export { default as CardReaderBar } from './CardReaderBar'
export { default as CardApplyDialog, type CardFields } from './CardApplyDialog'
export { IdCardProvider, useIdCardConfig, type IdCardConfig } from './context'
export { registerIdCardI18n, idCardResources, ID_CARD_NS } from './i18n'
export { defaultUi, type UiKit } from './ui'
