import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { honorApi } from '../../api/engagement'
import Icon from '../../components/Icon'
import { LogoMark, OrnamentDivider, OrnamentPattern, OrnamentStrip } from '../../components/ornaments'
import { formatDate, formatNumber, formatTime } from '../../lib/format'
import { MEDAL, monthLabel } from './HonorBoardPage'

/**
 * Public TV screen for the centre (no login). One track per screen (?gender=male|female), refreshes every minute,
 * shows only published boards and only first and second names, never photos.
 */
export default function HonorDisplayPage() {
  const [params] = useSearchParams()
  const { t, i18n } = useTranslation('engagement')
  const key = params.get('key') ?? ''
  const gender = params.get('gender') === 'female' ? 'female' : 'male'
  const lang = params.get('lang')

  useEffect(() => {
    if (lang && (lang === 'ar' || lang === 'en') && i18n.language !== lang) void i18n.changeLanguage(lang)
  }, [lang, i18n])

  const locale = i18n.language
  const q = useQuery({ queryKey: ['honor-display', key, gender, locale], queryFn: () => honorApi.display(key, gender, locale), refetchInterval: 60_000, retry: false })
  const [now, setNow] = useState(new Date())
  useEffect(() => { const id = setInterval(() => setNow(new Date()), 30_000); return () => clearInterval(id) }, [])

  const board = q.data?.board
  const n = (v: number, d = 0) => formatNumber(v, locale, { maximumFractionDigits: d })
  const top = board?.rows.slice(0, 3) ?? []
  const rest = board?.rows.slice(3, 10) ?? []
  const hhmm = now.toLocaleTimeString('en-GB', { timeZone: 'Asia/Bahrain', hour: '2-digit', minute: '2-digit' })

  return (
    <div className="relative min-h-screen overflow-hidden bg-deep text-white">
      <OrnamentPattern className="pointer-events-none absolute inset-0 text-gold-300/10" />
      <OrnamentStrip className="relative text-gold-400" />
      <header className="relative mx-auto flex max-w-7xl items-center gap-4 px-6 py-5">
        <LogoMark className="size-14" />
        <div>
          <h1 className="font-display text-3xl text-gold-300 lg:text-5xl">{t('display.title')} · {gender === 'female' ? t('display.girls') : t('display.boys')}</h1>
          {board && <p className="mt-1 text-lg text-white/75">{t('display.month', { month: monthLabel(board.period, locale) })}</p>}
        </div>
        <div className="ms-auto text-end">
          <p className="font-display text-3xl tabular-nums text-gold-300">{formatTime(hhmm, locale)}</p>
          <p className="text-sm text-white/60">{formatDate(now, locale, { weekday: 'long', day: 'numeric', month: 'long' })}</p>
        </div>
      </header>

      <main className="relative mx-auto max-w-7xl px-6 pb-10">
        {q.isError ? (
          <p className="mt-24 text-center text-2xl text-white/70">{t('display.denied')}</p>
        ) : !board ? (
          <div className="mt-24 text-center">
            <Icon name="medal" className="mx-auto size-16 text-gold-400" />
            <p className="mt-4 text-2xl text-white/75">{q.isLoading ? '' : t('display.nothing')}</p>
          </div>
        ) : (
          <>
            <section className="mt-4 grid gap-5 md:grid-cols-3 md:items-end" aria-label={t('honor.podium')}>
              {[1, 0, 2].filter((i) => top[i]).map((i) => {
                const r = top[i]
                const place = r.rank ?? i + 1
                return (
                  <div key={r.student?.id} className={`flex flex-col items-center rounded-3xl border border-gold-400/30 bg-white/5 p-6 text-center ${i === 0 ? 'order-first md:order-none md:pb-12 md:pt-10' : ''}`}>
                    <Icon name="medal" className={`size-12 ${MEDAL[Math.min(place, 3) - 1] === 'text-ink/45' ? 'text-white/70' : MEDAL[Math.min(place, 3) - 1]}`} />
                    <span className="mt-3 grid size-20 place-items-center rounded-full bg-gold-400/20 font-display text-4xl text-gold-300">{r.student?.initial}</span>
                    <p dir="auto" className="mt-3 font-display text-3xl">{r.student?.full_name}</p>
                    <p dir="auto" className="text-white/60">{r.lesson?.name}</p>
                    <p className="mt-2 font-display text-4xl tabular-nums text-gold-300">{n(r.points, 1)}</p>
                    <p className="text-sm text-white/55">{t('honor.place', { n: n(place) })}</p>
                  </div>
                )
              })}
            </section>

            <div className="mt-8 grid gap-6 lg:grid-cols-3">
              <ol className="space-y-2 lg:col-span-2">
                {rest.map((r) => (
                  <li key={r.student?.id} className="flex items-center gap-4 rounded-2xl bg-white/5 px-5 py-3 text-xl">
                    <span className="w-10 font-display text-2xl tabular-nums text-gold-300">{r.rank !== null ? n(r.rank) : ''}</span>
                    <span dir="auto" className="flex-1">{r.student?.full_name}<span className="ms-3 text-base text-white/50">{r.lesson?.name}</span></span>
                    <span className="font-display tabular-nums text-gold-300">{n(r.points, 1)}</span>
                  </li>
                ))}
              </ol>
              <div className="space-y-5">
                {board.circle_of_month && (
                  <div className="rounded-3xl border border-gold-400/30 bg-white/5 p-6 text-center">
                    <p className="text-lg text-gold-300">{t('display.circle_of_month')}</p>
                    <Icon name="trophy" className="mx-auto mt-2 size-12 text-gold-400" />
                    <p dir="auto" className="mt-2 font-display text-3xl">{board.circle_of_month.name}</p>
                    <OrnamentDivider align="center" className="my-3 text-gold-400" />
                    <p dir="auto" className="text-white/65">{board.circle_of_month.teacher}</p>
                  </div>
                )}
                {(q.data?.badges.length ?? 0) > 0 && (
                  <div className="rounded-3xl bg-white/5 p-5">
                    <p className="mb-3 text-lg text-gold-300">{t('display.badges')}</p>
                    <ul className="space-y-2">
                      {q.data!.badges.slice(0, 6).map((b, i) => (
                        <li key={i} className="flex items-center gap-2"><Icon name="medal" className="size-5 text-gold-400" /><span dir="auto">{b.student}</span><span dir="auto" className="ms-auto text-sm text-white/60">{b.badge}</span></li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            </div>
          </>
        )}
      </main>
    </div>
  )
}
