/** Locale-aware number formatting: Arabic-Indic digits for ar, Latin for en. */
export function formatNumber(value: number, locale: string, options: Intl.NumberFormatOptions = {}): string {
  return new Intl.NumberFormat(locale === 'ar' ? 'ar-BH' : 'en-BH', options).format(value)
}
