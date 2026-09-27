import { api } from '../api/client'

export type OrnamentLevel = 'full' | 'minimal' | 'off'
const LEVELS: OrnamentLevel[] = ['full', 'minimal', 'off']
const STORAGE_KEY = 'ahl.ornament'

const isLevel = (v: unknown): v is OrnamentLevel => LEVELS.includes(v as OrnamentLevel)

/** Set html[data-ornament]; the CSS tokens in index.css do the rest. */
export function applyOrnamentLevel(level: OrnamentLevel) {
  document.documentElement.dataset.ornament = level
  try {
    localStorage.setItem(STORAGE_KEY, level)
  } catch {
    /* storage unavailable */
  }
}

/** Apply the last known level at once (no flash), then refresh it from the ui.ornament_level setting. */
export function initOrnamentLevel() {
  let stored: string | null = null
  try {
    stored = localStorage.getItem(STORAGE_KEY)
  } catch {
    /* storage unavailable */
  }
  document.documentElement.dataset.ornament = isLevel(stored) ? stored : 'full'

  api
    .get<{ ornament_level?: string }>('/public/settings')
    .then(({ data }) => isLevel(data.ornament_level) && applyOrnamentLevel(data.ornament_level))
    .catch(() => undefined)
}
