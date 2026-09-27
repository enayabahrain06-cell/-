/** Convert Arabic-Indic and Persian digits to Latin so users can type in either keyboard. */
export function toLatinDigits(value: string): string {
  return value
    .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
    .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
}

/** Loose client check; the server normalises and validates the number authoritatively. */
export function looksLikePhone(value: string): boolean {
  const digits = toLatinDigits(value).replace(/[\s\-()]/g, '')
  return /^\+?\d{8,15}$/.test(digits)
}
