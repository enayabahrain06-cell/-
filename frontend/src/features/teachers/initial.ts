/** First letter of the name itself, skipping an honorific such as «أ.», «الأستاذة» or «الشيخ». */
const HONORIFIC = /^(?:أ\s*[./]|الأستاذة|الأستاذ|الشيخة|الشيخ|د\s*\.|mrs?\.?|ms\.?|dr\.?|sheikh)\s*/i

export function initialOf(name: string): string {
  return name.trim().replace(HONORIFIC, '').trim().charAt(0) || name.trim().charAt(0)
}
