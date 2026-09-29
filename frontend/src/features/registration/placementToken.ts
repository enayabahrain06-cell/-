/**
 * The placement attempt token, kept per package in sessionStorage so a reload or a closed tab resumes the
 * same attempt. Storage can be blocked (private mode, previews), so every access is guarded.
 */
const key = (packageId: number) => `ahl.placement.${packageId}`

export function readPlacementToken(packageId: number): string | null {
  try {
    return sessionStorage.getItem(key(packageId))
  } catch {
    return null
  }
}

export function writePlacementToken(packageId: number, token: string): void {
  try {
    sessionStorage.setItem(key(packageId), token)
  } catch {
    /* storage blocked: the attempt still works until the page is closed */
  }
}

export function clearPlacementToken(packageId: number): void {
  try {
    sessionStorage.removeItem(key(packageId))
  } catch {
    /* storage blocked */
  }
}
