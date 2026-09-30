import type { TFunction } from 'i18next'
import type { AttendanceStatus, RosterRow, SessionInfo } from '../../api/attendance'
import { formatDate, formatNumber, formatTime } from '../../lib/format'

type Status = Record<number, AttendanceStatus | null>

const esc = (v: string) => v.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!)

function counts(roster: RosterRow[], status: Status) {
  const c = { present: 0, late: 0, absent: 0, excused: 0 }
  for (const r of roster) { const s = status[r.student.id]; if (s) c[s]++ }
  return c
}

function summary(t: TFunction, locale: string, roster: RosterRow[], status: Status) {
  const c = counts(roster, status)
  return t('quick.summary', { present: formatNumber(c.present, locale), late: formatNumber(c.late, locale), absent: formatNumber(c.absent, locale), excused: formatNumber(c.excused, locale) })
}

/**
 * Prints the session's attendance in its own window: a plain black-on-white sheet (header, summary, one row per
 * student), so the app's layout and colours never reach the paper.
 */
export function printAttendance(t: TFunction, locale: string, dir: 'rtl' | 'ltr', s: SessionInfo, roster: RosterRow[], status: Status) {
  const w = window.open('', '_blank', 'width=900,height=700')
  if (!w) return
  const facts = [
    formatDate(s.session_date, locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
    `${formatTime(s.start_time, locale)}–${formatTime(s.end_time, locale)}`,
    s.location ? `${t('quick.hall')}: ${s.location.name}` : '',
    s.lesson?.teacher ? `${t('quick.teacher')}: ${s.lesson.teacher.name}` : '',
  ].filter(Boolean)
  const rows = roster.map((r, i) => {
    const st = status[r.student.id]
    return `<tr class="${st === 'absent' ? 'absent' : ''}"><td>${formatNumber(i + 1, locale)}</td><td dir="auto">${esc(r.student.full_name)}</td><td>${st ? esc(t(`status.${st}`)) : '—'}</td></tr>`
  }).join('')
  w.document.write(`<!doctype html><html lang="${locale}" dir="${dir}"><head><meta charset="utf-8"><title>${esc(t('sheet_title'))} — ${esc(s.lesson?.name ?? '')}</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600&display=swap">
<style>
  body { font-family: "IBM Plex Sans Arabic", system-ui, sans-serif; color: #111; margin: 24px; }
  h1 { font-size: 20px; margin: 0 0 4px; } p { margin: 2px 0; font-size: 13px; color: #333; }
  .facts { display: flex; flex-wrap: wrap; gap: 4px 16px; margin: 8px 0 12px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { border: 1px solid #999; padding: 6px 8px; text-align: start; } th { background: #eee; }
  td:first-child, th:first-child { width: 36px; text-align: center; } td:last-child, th:last-child { width: 110px; }
  tr.absent td { font-weight: 600; } .foot { margin-top: 12px; font-size: 11px; color: #666; }
  @page { margin: 14mm; }
</style></head><body>
<h1>${esc(t('sheet_title'))} — <span dir="auto">${esc(s.lesson?.name ?? '')}</span></h1>
<div class="facts">${facts.map((f) => `<p dir="auto">${esc(f)}</p>`).join('')}</div>
<p><strong>${esc(summary(t, locale, roster, status))}</strong></p>
<table><thead><tr><th>${esc(t('quick.col_no'))}</th><th>${esc(t('quick.col_student'))}</th><th>${esc(t('quick.col_status'))}</th></tr></thead><tbody>${rows}</tbody></table>
<p class="foot">${esc(t('quick.printed_at', { date: formatDate(new Date(), locale, { day: 'numeric', month: 'long', year: 'numeric', hour: 'numeric', minute: '2-digit' }) }))}</p>
</body></html>`)
  w.document.close()
  // Print once the web font is in, so the Arabic prints in the same face as the app.
  const go = () => { w.focus(); w.print() }
  if (w.document.fonts?.ready) void w.document.fonts.ready.then(go)
  else w.onload = go
}

/** A short text of the session's attendance, shared through the device's share sheet or WhatsApp. */
export function shareAttendance(t: TFunction, locale: string, s: SessionInfo, roster: RosterRow[], status: Status) {
  const names = (v: AttendanceStatus) => roster.filter((r) => status[r.student.id] === v).map((r) => r.student.full_name).join('، ')
  const absent = names('absent')
  const late = names('late')
  const text = [
    `${t('sheet_title')} — ${s.lesson?.name ?? ''}`,
    `${formatDate(s.session_date, locale, { weekday: 'long', day: 'numeric', month: 'long' })} · ${formatTime(s.start_time, locale)}`,
    summary(t, locale, roster, status),
    absent ? t('quick.absent_list', { names: absent }) : '',
    late ? t('quick.late_list', { names: late }) : '',
  ].filter(Boolean).join('\n')
  if (navigator.share) {
    navigator.share({ text }).catch(() => { /* dismissed */ })
    return
  }
  window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank', 'noopener')
}
