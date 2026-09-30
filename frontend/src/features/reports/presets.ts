const iso = (d: Date) => d.toLocaleDateString('en-CA')

/** This month, last month, and the school term (September–January or February–June), each up to today at most. */
export function presets(): { key: string; from: string; to: string }[] {
  const now = new Date()
  const y = now.getFullYear()
  const m = now.getMonth()
  const termStart = m >= 8 ? new Date(y, 8, 1) : m === 0 ? new Date(y - 1, 8, 1) : new Date(y, 1, 1)
  return [
    { key: 'this_month', from: iso(new Date(y, m, 1)), to: iso(now) },
    { key: 'last_month', from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) },
    { key: 'this_term', from: iso(termStart), to: iso(now) },
  ]
}
