import { useEffect, useState } from 'react'
import { api } from '../api/client'

/**
 * Gallery files are served only to signed-in users (no public or signed links), so <img src> cannot point at them:
 * each file is fetched with the session token and shown through an object URL. A small LRU keeps recent files so
 * scrolling back and the viewer do not fetch twice; evicted URLs are revoked.
 */
const LIMIT = 300
const cache = new Map<string, string>()
const pending = new Map<string, Promise<string>>()

function remember(key: string, url: string) {
  cache.set(key, url)
  while (cache.size > LIMIT) {
    const [oldest, old] = cache.entries().next().value as [string, string]
    cache.delete(oldest)
    URL.revokeObjectURL(old)
  }
}

export function loadAuthBlob(path: string): Promise<string> {
  const hit = cache.get(path)
  if (hit) {
    cache.delete(path)
    cache.set(path, hit)
    return Promise.resolve(hit)
  }
  let p = pending.get(path)
  if (!p) {
    p = api.get<Blob>(path, { responseType: 'blob' })
      .then((r) => {
        const url = URL.createObjectURL(r.data)
        remember(path, url)
        return url
      })
      .finally(() => pending.delete(path))
    pending.set(path, p)
  }
  return p
}

/** Object URL of an authenticated file, or null while loading; `failed` when the request failed. */
export function useAuthBlob(path: string | null) {
  const [state, setState] = useState<{ path: string | null; src: string | null; failed: boolean }>({ path: null, src: null, failed: false })
  useEffect(() => {
    // A cached file renders straight from the cache below; only a fetch sets state (when it settles).
    if (!path || cache.has(path)) return
    let live = true
    loadAuthBlob(path).then((src) => live && setState({ path, src, failed: false }), () => live && setState({ path, src: null, failed: true }))
    return () => { live = false }
  }, [path])
  return state.path === path ? { src: state.src, failed: state.failed } : { src: path ? cache.get(path) ?? null : null, failed: false }
}

/** Save an authenticated file (?download=1 is audited on the server). */
export async function downloadAuthFile(path: string, filename: string) {
  const r = await api.get<Blob>(path, { responseType: 'blob', params: { download: 1 } })
  const url = URL.createObjectURL(r.data)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
