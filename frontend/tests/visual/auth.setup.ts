import fs from 'node:fs'
import path from 'node:path'
import { test as setup, expect, type APIRequestContext } from '@playwright/test'
import { AUTH_DIR, IDS_FILE, STORAGE_STATE, TOKEN_KEY } from './paths'
import type { Ids } from './pages'

/**
 * Creates or reuses the staff session used by every visual test.
 *
 * Order:
 *  1. Reuse tests/visual/.auth/staff.json while its token is still accepted by /api/auth/me.
 *  2. VISUAL_STAFF_PHONE + VISUAL_STAFF_PASSWORD from the shell: sign in through the real login API.
 *  3. Headed run (`npm run test:visual:login`) or VISUAL_MANUAL_LOGIN=1: you sign in by hand in the window.
 *
 * Nothing is hard-coded and the .auth folder is git-ignored. The token is a normal Sanctum
 * token for that user; sign out in the app (or delete the file) to revoke or rotate it.
 */
const API = '/api'

function savedToken(): string | null {
  try {
    const state = JSON.parse(fs.readFileSync(STORAGE_STATE, 'utf8')) as { origins?: { localStorage?: { name: string; value: string }[] }[] }
    return state.origins?.flatMap((o) => o.localStorage ?? []).find((e) => e.name === TOKEN_KEY)?.value ?? null
  } catch {
    return null
  }
}

async function tokenWorks(request: APIRequestContext, token: string) {
  const r = await request.get(`${API}/auth/me`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
  return r.ok()
}

/** First record of each kind that detail pages need, so the suite adapts to whatever data is loaded. */
async function resolveIds(request: APIRequestContext, token: string): Promise<Ids> {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
  const first = async (url: string) => {
    const r = await request.get(`${API}${url}`, { headers })
    if (!r.ok()) return undefined
    const body = (await r.json()) as { data?: { id: number }[] }
    const id = body.data?.[0]?.id
    return id === undefined ? undefined : String(id)
  }
  const ids: Ids = {
    lesson: await first('/lessons?per_page=1'),
    hall: await first('/locations?all=1'),
    student: await first('/students?per_page=1'),
    exam: await first('/exams?per_page=1'),
    lottery: await first('/lotteries?per_page=1'),
    competition: await first('/competitions?per_page=1'),
  }
  // The pinned day for the day view, the sheets and the frozen browser clock. Order: VISUAL_DATE, then the
  // date pinned by an earlier run (so baselines do not move every day), then the most recent day with sessions.
  const sessionsOn = async (iso: string) => {
    const r = await request.get(`${API}/sessions/today`, { headers, params: { date: iso } })
    return r.ok() ? (((await r.json()) as { data?: { id: number }[] }).data ?? []) : []
  }
  let previous: Ids = {}
  try { previous = JSON.parse(fs.readFileSync(IDS_FILE, 'utf8')) as Ids } catch { /* first run */ }
  const candidates = [process.env.VISUAL_DATE, previous.sessionDate].filter((d): d is string => !!d)
  const day = new Date()
  for (let i = 0; i < 60; i++) candidates.push(new Date(day.getTime() - i * 864e5).toLocaleDateString('en-CA', { timeZone: 'Asia/Bahrain' }))
  for (const iso of candidates) {
    const sessions = await sessionsOn(iso)
    if (sessions.length) {
      ids.session = String(sessions[0].id)
      ids.sessionDate = iso
      break
    }
  }
  return ids
}

setup('staff session', async ({ page, request, headless }) => {
  setup.setTimeout(6 * 60_000)
  fs.mkdirSync(AUTH_DIR, { recursive: true })

  let token = savedToken()
  if (!token || !(await tokenWorks(request, token))) {
    token = null
    const phone = process.env.VISUAL_STAFF_PHONE
    const password = process.env.VISUAL_STAFF_PASSWORD

    if (phone && password) {
      const r = await request.post(`${API}/auth/login`, { data: { phone, password, device: 'visual-tests' }, headers: { Accept: 'application/json' } })
      expect(r.ok(), `Login for VISUAL_STAFF_PHONE failed (${r.status()}).`).toBeTruthy()
      token = ((await r.json()) as { token: string }).token
    } else if (!headless || process.env.VISUAL_MANUAL_LOGIN) {
      await page.goto('/login')
      console.log('\n  Sign in as a staff user in the browser window. Waiting up to 5 minutes…\n')
      await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 5 * 60_000 })
      token = await page.evaluate((k) => localStorage.getItem(k), TOKEN_KEY)
      expect(token, 'No token was stored after signing in.').toBeTruthy()
    } else {
      throw new Error(
        'No staff session for the visual tests.\n' +
          '  Run `npm run test:visual:login` once and sign in by hand, or set VISUAL_STAFF_PHONE and\n' +
          '  VISUAL_STAFF_PASSWORD in your shell (never in a committed file).',
      )
    }
  }

  // localStorage is per origin: always store the token under the current base URL, so a session
  // saved against one dev server port also works when the suite runs against another.
  if (!page.url().startsWith('http')) await page.goto('/login')
  await page.evaluate(([k, v]) => localStorage.setItem(k, v), [TOKEN_KEY, token!] as const)
  await page.context().storageState({ path: STORAGE_STATE })

  const ids = await resolveIds(request, token!)
  fs.writeFileSync(IDS_FILE, JSON.stringify(ids, null, 2))
  console.log(`  visual ids: ${path.basename(IDS_FILE)} ${JSON.stringify(ids)}`)
})
