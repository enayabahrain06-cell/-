/** Local, git-ignored files the visual suite writes. Nothing here is committed. */
export const AUTH_DIR = 'tests/visual/.auth'
/** Playwright storageState holding the staff bearer token (localStorage `ahl.token`). */
export const STORAGE_STATE = `${AUTH_DIR}/staff.json`
/** Record ids for detail pages, looked up from the API on each run. */
export const IDS_FILE = `${AUTH_DIR}/ids.json`
/** Layout-check results per page (JSON) and the combined report (Markdown). */
export const LAYOUT_DIR = 'test-results/visual-layout'

export const TOKEN_KEY = 'ahl.token'
export const LOCALE_KEY = 'ahl.locale'
