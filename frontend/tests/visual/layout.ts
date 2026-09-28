import type { Page } from '@playwright/test'

/**
 * Layout checks run inside the page at the current viewport.
 *
 * `error` findings fail the test (page-level horizontal overflow, content escaping the viewport,
 * wrong document direction). `warn` findings are heuristics: they go into the report and the test
 * annotations for a human to judge, and never fail a run on their own.
 */
export type Finding = { level: 'error' | 'warn'; check: string; where: string; detail: string }
export type LayoutResult = { width: number; pageHeight: number; cls: number; findings: Finding[] }

export async function checkLayout(page: Page, expectedDir: 'rtl' | 'ltr'): Promise<LayoutResult> {
  return page.evaluate((dir) => {
    const findings: Finding[] = []
    const vw = document.documentElement.clientWidth
    const add = (level: Finding['level'], check: string, el: Element | null, detail: string) =>
      findings.push({ level, check, where: el ? describe(el) : 'document', detail })

    function describe(el: Element): string {
      const parts: string[] = []
      let node: Element | null = el
      for (let i = 0; node && i < 3 && node !== document.body; i++, node = node.parentElement) {
        const cls = [...node.classList].filter((c) => !c.includes(':') && !c.startsWith('[')).slice(0, 3).join('.')
        parts.unshift(`${node.tagName.toLowerCase()}${node.id ? `#${node.id}` : ''}${cls ? `.${cls}` : ''}`)
      }
      const text = (el.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 40)
      return `${parts.join(' > ')}${text ? ` "${text}"` : ''}`
    }

    const visible = (el: Element) => {
      const s = getComputedStyle(el)
      if (s.display === 'none' || s.visibility === 'hidden' || Number(s.opacity) === 0) return false
      const r = el.getBoundingClientRect()
      if (r.width < 2 || r.height < 2) return false // sr-only and collapsed nodes
      return !el.closest('[aria-hidden="true"], [inert], .sr-only')
    }
    /** Takes up space on screen (decorative aria-hidden avatars and icons included): used for gap measurements. */
    const rendered = (el: Element) => {
      const s = getComputedStyle(el)
      const r = el.getBoundingClientRect()
      return s.display !== 'none' && s.visibility !== 'hidden' && s.position !== 'absolute' && s.position !== 'fixed' && r.width >= 2 && r.height >= 2
    }
    const bordered = (el: Element) => parseFloat(getComputedStyle(el).borderTopWidth) > 0 && parseFloat(getComputedStyle(el).borderRadius) >= 8
    /** The card in a grid cell: the cell itself, or the single card inside a plain wrapper div. */
    const cardOf = (el: Element) => (bordered(el) ? el : el.children.length === 1 && bordered(el.children[0]) ? el.children[0] : null)
    /** Nearest ancestor that clips or scrolls horizontally: content inside it may legally extend past the viewport. */
    const clipper = (el: Element) => {
      for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
        const ox = getComputedStyle(p).overflowX
        // A full-width wrapper with overflow hidden would mask real page overflow, so it does not count.
        if ((ox === 'hidden' || ox === 'clip') && p.getBoundingClientRect().width >= vw - 1) continue
        if (ox !== 'visible') return p
      }
      return null
    }

    // 1. Direction matches the locale.
    if (document.documentElement.dir !== dir) add('error', 'direction', null, `html dir="${document.documentElement.dir}", expected "${dir}"`)

    // 2. Page-level horizontal scroll.
    const sw = document.documentElement.scrollWidth
    if (sw > vw + 1) add('error', 'horizontal-overflow', null, `scrollWidth ${sw}px > viewport ${vw}px`)

    const all = [...document.body.querySelectorAll('*')].filter(visible)

    // 3. Elements escaping the viewport (outermost offender only).
    const escaped = new Set<Element>()
    for (const el of all) {
      if (clipper(el)) continue
      const r = el.getBoundingClientRect()
      if (r.right > vw + 1 || r.left < -1) {
        if ([...escaped].some((e) => e.contains(el))) continue
        escaped.add(el)
        add('error', 'viewport-escape', el, `x ${Math.round(r.left)}→${Math.round(r.right)} in ${vw}px`)
      }
    }

    for (const el of all) {
      const s = getComputedStyle(el)
      const r = el.getBoundingClientRect()
      const hasText = [...el.childNodes].some((n) => n.nodeType === Node.TEXT_NODE && n.textContent!.trim().length > 0)

      // 4. Clipped text: hidden overflow on text with no ellipsis or line clamp.
      if (hasText && (s.overflowX === 'hidden' || s.overflowX === 'clip') && el.scrollWidth > el.clientWidth + 1 && s.textOverflow !== 'ellipsis') {
        add('warn', 'clipped-text', el, `content ${el.scrollWidth}px in ${el.clientWidth}px, no ellipsis`)
      }
      if (hasText && (s.overflowY === 'hidden' || s.overflowY === 'clip') && el.scrollHeight > el.clientHeight + 2 && s.webkitLineClamp === 'none') {
        add('warn', 'clipped-text', el, `content ${el.scrollHeight}px tall in ${el.clientHeight}px`)
      }

      // 5. Broken wrapping: a text run squeezed into a column so narrow it stacks word by word.
      if (hasText && s.display !== 'inline') {
        const lh = parseFloat(s.lineHeight) || parseFloat(s.fontSize) * 1.5
        const words = (el.textContent ?? '').trim().split(/\s+/).length
        if (words >= 3 && r.width < 72 && r.height > lh * 3.5) add('warn', 'broken-wrapping', el, `${Math.round(r.width)}px wide, ~${Math.round(r.height / lh)} lines for ${words} words`)
      }

      // 6. Grids: items spilling out of their container, and uneven card heights in one row.
      if (s.display === 'grid' || s.display === 'inline-grid') {
        const items = [...el.children].filter(visible)
        for (const it of items) {
          const ir = it.getBoundingClientRect()
          if (ir.right > r.right + 1 || ir.left < r.left - 1) add('warn', 'grid-overflow', it, `item x ${Math.round(ir.left)}→${Math.round(ir.right)} outside grid ${Math.round(r.left)}→${Math.round(r.right)}`)
        }
        const rows = new Map<number, Element[]>()
        for (const it of items) {
          const top = Math.round(it.getBoundingClientRect().top / 4) * 4
          rows.set(top, [...(rows.get(top) ?? []), it])
        }
        for (const row of rows.values()) {
          const cards = row.map(cardOf).filter((c): c is Element => c !== null)
          if (cards.length < 2) continue
          const hs = cards.map((c) => c.getBoundingClientRect().height)
          const min = Math.min(...hs), max = Math.max(...hs)
          if (max - min > 48 && max / min > 1.3) add('warn', 'card-height-mismatch', el, `cards in one row ${Math.round(min)}–${Math.round(max)}px tall`)
        }
      }

      // 7. Excessive whitespace between stacked siblings in the main column.
      if (el.closest('main') && el.children.length >= 2 && (s.display === 'block' || s.flexDirection === 'column')) {
        const kids = [...el.children].filter(rendered).map((k) => k.getBoundingClientRect())
        for (let i = 1; i < kids.length; i++) {
          const gap = kids[i].top - kids[i - 1].bottom
          if (gap > 96) { add('warn', 'excessive-whitespace', el, `${Math.round(gap)}px gap between children ${i} and ${i + 1}`); break }
        }
      }
    }

    // 8. Fonts that failed to load change metrics everywhere.
    for (const f of document.fonts) if (f.status === 'error') add('warn', 'font-load', null, `${f.family} failed to load`)

    const cls = (window as unknown as { __cls?: number }).__cls ?? 0
    return { width: vw, pageHeight: document.documentElement.scrollHeight, cls, findings }
  }, expectedDir)
}
