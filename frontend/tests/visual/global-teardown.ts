import fs from 'node:fs'
import type { Finding, LayoutResult } from './layout'
import { LAYOUT_DIR } from './paths'

type PageFile = { page: string; family: string; path: string; locale: 'ar' | 'en'; results: LayoutResult[] }

/**
 * Writes test-results/visual-layout/report.md: every layout finding by page and width, plus an
 * RTL/LTR comparison (page height and findings that appear in only one direction).
 */
export default function globalTeardown() {
  if (!fs.existsSync(LAYOUT_DIR)) return
  const files = fs.readdirSync(LAYOUT_DIR).filter((f) => f.endsWith('.json'))
  if (!files.length) return
  const data = files.map((f) => JSON.parse(fs.readFileSync(`${LAYOUT_DIR}/${f}`, 'utf8')) as PageFile)
  const byPage = new Map<string, Partial<Record<'ar' | 'en', PageFile>>>()
  for (const d of data) byPage.set(d.page, { ...byPage.get(d.page), [d.locale]: d })

  const key = (f: Finding) => `${f.check}|${f.where.replace(/ ".*"$/, '')}`
  const lines: string[] = ['# Visual layout report', '']
  let errors = 0
  let warns = 0

  for (const [name, loc] of [...byPage].sort()) {
    const out: string[] = []
    for (const locale of ['ar', 'en'] as const) {
      const d = loc[locale]
      if (!d) continue
      for (const r of d.results) {
        for (const f of r.findings) {
          if (f.level === 'error') errors++
          else warns++
          out.push(`- ${f.level === 'error' ? '**error**' : 'warn'} · ${locale} · ${r.width}px · \`${f.check}\` · ${f.where} · ${f.detail}`)
        }
      }
      const cls = d.results[0]?.cls ?? 0
      if (cls > 0.1) out.push(`- warn · ${locale} · load · \`layout-shift\` · CLS ${cls.toFixed(3)} while loading`)
    }
    if (loc.ar && loc.en) {
      for (let i = 0; i < loc.ar.results.length; i++) {
        const a = loc.ar.results[i]
        const e = loc.en.results.find((x) => x.width === a.width)
        if (!e) continue
        const ratio = Math.max(a.pageHeight, e.pageHeight) / Math.max(1, Math.min(a.pageHeight, e.pageHeight))
        if (ratio > 1.25) out.push(`- warn · ar↔en · ${a.width}px · \`rtl-ltr-height\` · page height ar ${a.pageHeight}px vs en ${e.pageHeight}px`)
        const ak = new Set(a.findings.map(key))
        const ek = new Set(e.findings.map(key))
        for (const f of a.findings) if (!ek.has(key(f))) out.push(`- warn · ar only · ${a.width}px · \`rtl-ltr-diff\` · ${f.check} · ${f.where}`)
        for (const f of e.findings) if (!ak.has(key(f))) out.push(`- warn · en only · ${e.width}px · \`rtl-ltr-diff\` · ${f.check} · ${f.where}`)
      }
    }
    lines.push(`## ${name} (${(loc.ar ?? loc.en)!.path})`, '', ...(out.length ? [...new Set(out)] : ['No findings.']), '')
  }

  lines.splice(2, 0, `${byPage.size} pages · ${errors} errors · ${warns} warnings (heuristics, review by eye)`, '')
  fs.writeFileSync(`${LAYOUT_DIR}/report.md`, lines.join('\n'))
  console.log(`\n  Layout report: ${LAYOUT_DIR}/report.md (${errors} errors, ${warns} warnings)\n`)
}
