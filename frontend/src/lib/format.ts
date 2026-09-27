/** Locale-aware formatting: Arabic-Indic digits for ar, Latin for en; BHD with 3 decimals; Hijri next to Gregorian. */
const tag = (locale: string) => (locale === 'ar' ? 'ar-BH' : 'en-BH')

export function formatNumber(value: number, locale: string, options: Intl.NumberFormatOptions = {}): string {
  return new Intl.NumberFormat(tag(locale), options).format(value)
}

export function formatPercent(value: number | null | undefined, locale: string): string {
  if (value === null || value === undefined) return '—'
  return new Intl.NumberFormat(tag(locale), { style: 'percent', maximumFractionDigits: 0 }).format(value / 100)
}

/** Money is stored in fils (1 BHD = 1000 fils). */
export function formatMoney(fils: number, locale: string): string {
  return new Intl.NumberFormat(tag(locale), { style: 'currency', currency: 'BHD', minimumFractionDigits: 3 }).format(fils / 1000)
}

function toDate(value: string | Date): Date {
  // Plain YYYY-MM-DD strings are dates in Bahrain, not UTC midnight.
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00+03:00`) : new Date(value)
}

export function formatDate(value: string | Date, locale: string, options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'long', year: 'numeric' }): string {
  return new Intl.DateTimeFormat(tag(locale), { timeZone: 'Asia/Bahrain', ...options }).format(toDate(value))
}

export function formatHijri(value: string | Date, locale: string): string {
  return new Intl.DateTimeFormat(`${locale === 'ar' ? 'ar-SA' : 'en'}-u-ca-islamic-umalqura`, {
    timeZone: 'Asia/Bahrain', day: 'numeric', month: 'long', year: 'numeric',
  }).format(toDate(value))
}

export function formatWeekday(value: string | Date, locale: string, style: 'long' | 'short' = 'long'): string {
  return new Intl.DateTimeFormat(tag(locale), { timeZone: 'Asia/Bahrain', weekday: style }).format(toDate(value))
}

/** "16:00" → localized short time. */
export function formatTime(hhmm: string, locale: string): string {
  const [h, m] = hhmm.split(':').map(Number)
  const d = new Date(Date.UTC(2000, 0, 1, h - 3, m)) // Bahrain is UTC+3 all year
  return new Intl.DateTimeFormat(tag(locale), { timeZone: 'Asia/Bahrain', hour: 'numeric', minute: '2-digit' }).format(d)
}
