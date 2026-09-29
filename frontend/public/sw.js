/*
 * Service worker for the installable app (PWA). Deliberately small:
 *  - /assets/* (Vite's hashed, immutable build files): cache first.
 *  - Page navigations: network first; the last good app shell is used only when offline.
 *  - Google Fonts: served from cache, refreshed in the background.
 *  - Everything else, and always /api and /media: straight to the network, never cached,
 *    so data (attendance, payments, students) is never stale.
 * Registered with ?dev=1 by the Vite dev server: then it caches nothing (installable, no stale code).
 */
const VERSION = 'v1'
const SHELL = `shell-${VERSION}`
const ASSETS = `assets-${VERSION}`
const FONTS = `fonts-${VERSION}`
const DEV = new URL(self.location.href).searchParams.has('dev')

self.addEventListener('install', () => self.skipWaiting())

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keep = [SHELL, ASSETS, FONTS]
    for (const key of await caches.keys()) if (!keep.includes(key)) await caches.delete(key)
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  if (DEV || req.method !== 'GET') return
  const url = new URL(req.url)

  if (url.origin === self.location.origin) {
    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/media/')) return
    if (req.mode === 'navigate') {
      event.respondWith((async () => {
        try {
          const res = await fetch(req)
          if (res.ok) (await caches.open(SHELL)).put('/', res.clone())
          return res
        } catch {
          return (await caches.match('/')) || Response.error()
        }
      })())
      return
    }
    if (url.pathname.startsWith('/assets/')) {
      event.respondWith((async () => {
        const hit = await caches.match(req)
        if (hit) return hit
        const res = await fetch(req)
        if (res.ok) (await caches.open(ASSETS)).put(req, res.clone())
        return res
      })())
    }
    return
  }

  if (url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
    event.respondWith((async () => {
      const cache = await caches.open(FONTS)
      const hit = await cache.match(req)
      const refresh = fetch(req).then((res) => { if (res.ok) cache.put(req, res.clone()); return res }).catch(() => hit)
      return hit || refresh
    })())
  }
})
