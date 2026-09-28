import fs from 'node:fs'
import { test, expect, FREEZE_CSS } from './fixtures'
import { checkLayout, type LayoutResult } from './layout'
import { IDS_FILE, LAYOUT_DIR } from './paths'
import { MEMBER_WIDTHS, PAGES, WIDTHS, type Ids } from './pages'

const ids: Ids = fs.existsSync(IDS_FILE) ? JSON.parse(fs.readFileSync(IDS_FILE, 'utf8')) : {}
/** VISUAL_ALL_WIDTHS=1 screenshots every page at every width (about 490 images instead of about 230). */
const allWidths = !!process.env.VISUAL_ALL_WIDTHS

const heightFor = (w: number) => (w < 768 ? 844 : w < 1280 ? 1024 : 900)

/** Wait until data has loaded: no spinner, no skeleton, fonts ready, then two quiet frames. */
async function settle(page: import('@playwright/test').Page) {
  await page.waitForLoadState('networkidle')
  await expect(page.locator('main [role="status"]:has(svg), main .animate-pulse')).toHaveCount(0, { timeout: 20_000 })
  await page.evaluate(async () => {
    await document.fonts.ready
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))
  })
}

for (const p of PAGES) {
  test(`${p.family} · ${p.name}`, async ({ page, appLocale }, info) => {
    const path = typeof p.path === 'function' ? p.path(ids) : p.path
    test.skip(path === null, `No record in this database for ${p.name}`)

    // Pages that follow the browser's "today" run on the pinned session date (noon, Bahrain time).
    if (p.pinClock && ids.sessionDate) await page.clock.setFixedTime(new Date(`${ids.sessionDate}T12:00:00+03:00`))
    await page.setViewportSize({ width: WIDTHS[0], height: heightFor(WIDTHS[0]) })
    await page.goto(path!)
    await expect(page, 'Session expired or permission missing: the page redirected').not.toHaveURL(/\/login/)
    await page.addStyleTag({ content: FREEZE_CSS })
    await settle(page)

    const results: LayoutResult[] = []
    const shotWidths = p.master || allWidths ? WIDTHS : MEMBER_WIDTHS

    for (const w of WIDTHS) {
      await test.step(`${w}px`, async () => {
        await page.setViewportSize({ width: w, height: heightFor(w) })
        await settle(page)

        const r = await checkLayout(page, appLocale === 'ar' ? 'rtl' : 'ltr')
        results.push(r)
        for (const f of r.findings.filter((x) => x.level === 'error')) {
          expect.soft(f, `${w}px ${f.check}: ${f.where} (${f.detail})`).toBeUndefined()
        }
        for (const f of r.findings.filter((x) => x.level === 'warn')) {
          info.annotations.push({ type: `warn:${f.check}`, description: `${w}px ${f.where}: ${f.detail}` })
        }

        if (shotWidths.includes(w)) {
          await expect.soft(page).toHaveScreenshot(`${p.name}-${w}.png`, { fullPage: true, mask: (p.mask ?? []).map((s) => page.locator(s)) })
        }
      })
    }

    fs.mkdirSync(LAYOUT_DIR, { recursive: true })
    fs.writeFileSync(`${LAYOUT_DIR}/${appLocale}-${p.name}.json`, JSON.stringify({ page: p.name, family: p.family, path, locale: appLocale, results }, null, 2))
  })
}
