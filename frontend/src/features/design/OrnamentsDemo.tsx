import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../../components/LanguageSwitcher'
import {
  EmptyState,
  Khatam,
  LogoMark,
  OrnamentDivider,
  OrnamentFrame,
  OrnamentPattern,
  OrnamentStrip,
  PageTitle,
  StarSpinner,
} from '../../components/ornaments'
import type { OrnamentLevel } from '../../lib/ornament'

const LEVELS: OrnamentLevel[] = ['full', 'minimal', 'off']

/** Living reference for the ornament system: every motif in both themes; the language switch flips direction. */
export default function OrnamentsDemo() {
  const { t } = useTranslation('design')
  const [level, setLevel] = useState<OrnamentLevel>('full')

  return (
    <div data-ornament={level} className="min-h-screen bg-page">
      <OrnamentStrip className="text-gold-500" />
      <div className="mx-auto max-w-6xl space-y-10 px-4 py-8 sm:px-8">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <PageTitle>{t('title')}</PageTitle>
          <div className="flex flex-wrap items-center gap-2">
            <div role="radiogroup" aria-label={t('level')} className="inline-flex rounded-full border border-ink/15 bg-white p-0.5 text-sm">
              {LEVELS.map((l) => (
                <button
                  key={l}
                  type="button"
                  role="radio"
                  aria-checked={level === l}
                  onClick={() => setLevel(l)}
                  className={`rounded-full px-3 py-1 font-medium ${level === l ? 'bg-brand-700 text-white' : 'text-ink/70 hover:bg-ink/5'}`}
                >
                  {t(`levels.${l}`)}
                </button>
              ))}
            </div>
            <LanguageSwitcher className="text-ink/70" />
          </div>
        </div>
        <p className="max-w-3xl text-sm text-ink/70">{t('intro')}</p>

        <Section title={t('sections.marks')} note="khatam.svg · logo-mark.svg · favicon.svg">
          {(dark) => (
            <div className={`flex flex-wrap items-center gap-6 ${dark ? 'text-gold-400' : 'text-brand-600'}`}>
              <Khatam className="size-16" />
              <Khatam className="size-8" />
              <LogoMark className="size-14" />
              <img src="/favicon.svg" alt="" className="size-8" />
              <StarSpinner className="size-10" label={t('loading')} />
            </div>
          )}
        </Section>

        <Section title={t('sections.pattern')} note="girih-tile.svg · .ornament-pattern · --ornament-opacity · --ornament-size">
          {(dark) => (
            <div className={`relative h-40 overflow-hidden rounded-xl ${dark ? 'bg-deep' : 'bg-page ring-1 ring-ink/10'}`}>
              <OrnamentPattern className={dark ? 'text-gold-300' : 'text-brand-600'} />
              <p className={`relative p-5 font-display text-2xl ${dark ? 'text-gold-300' : 'text-brand-900'}`}>{t('band_title')}</p>
            </div>
          )}
        </Section>

        <Section title={t('sections.divider')} note="divider.svg · <OrnamentDivider />">
          {(dark) => (
            <div className="space-y-5">
              <div>
                <p className={`font-display text-2xl ${dark ? 'text-gold-300' : 'text-ink'}`}>{t('page_title')}</p>
                <OrnamentDivider className={`mt-2 ${dark ? 'text-gold-400' : 'text-gold-500'}`} />
              </div>
              <OrnamentDivider align="center" className={dark ? 'text-gold-400' : 'text-brand-600'} />
              <div className={`overflow-hidden rounded-lg ${dark ? 'ring-1 ring-gold-300/20' : 'ring-1 ring-ink/10'}`}>
                <OrnamentStrip className={dark ? 'text-gold-400' : 'text-brand-600'} />
                <p className={`px-4 py-3 text-sm ${dark ? 'text-white/80' : 'text-ink/70'}`}>{t('strip_note')}</p>
              </div>
            </div>
          )}
        </Section>

        <Section title={t('sections.frame')} note="medallion.svg · certificate-frame.svg (A4, print) · <OrnamentFrame />" stacked>
          {(dark) => <CertificateSample dark={dark} />}
        </Section>

        <Section title={t('sections.empty')} note="<EmptyState />">
          {(dark) =>
            dark ? (
              <div className="grid place-items-center py-6 text-gold-400">
                <StarSpinner className="size-12" />
              </div>
            ) : (
              <div className="rounded-xl bg-white ring-1 ring-ink/10">
                <EmptyState icon="students" title={t('empty_title')} body={t('empty_body')} />
              </div>
            )
          }
        </Section>

        <Section title={t('sections.type')} note="Amiri · KFGQPC Uthmanic / Amiri Quran · IBM Plex Sans Arabic · Inter">
          {(dark) => (
            <div className={`space-y-3 ${dark ? 'text-gold-300' : 'text-ink'}`}>
              <p lang="ar" dir="rtl" className="font-display text-3xl">نظام أهل القرآن</p>
              <p lang="ar" dir="rtl" className="font-quran text-3xl leading-[2]">﴿ وَلَقَدْ يَسَّرْنَا الْقُرْآنَ لِلذِّكْرِ فَهَلْ مِن مُّدَّكِرٍ ﴾</p>
              <p lang="ar" dir="rtl" className={dark ? 'text-white/85' : 'text-ink/80'}>نص تجريبي بخط IBM Plex Sans Arabic للنصوص العادية.</p>
              <p lang="en" dir="ltr" className={`font-[Inter] ${dark ? 'text-white/85' : 'text-ink/80'}`}>Sample body text set in Inter for the English interface.</p>
            </div>
          )}
        </Section>
      </div>
    </div>
  )
}

function Section({ title, note, stacked, children }: { title: string; note: string; stacked?: boolean; children: (dark: boolean) => ReactNode }) {
  const { t } = useTranslation('design')
  return (
    <section className="space-y-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-lg font-semibold text-ink">{title}</h2>
        <code dir="ltr" className="text-xs text-ink/55">{note}</code>
      </div>
      <div className={`grid gap-4 ${stacked ? '' : 'md:grid-cols-2'}`}>
        <div className="rounded-2xl bg-white/60 p-5 ring-1 ring-ink/10">
          <p className="mb-3 text-xs font-medium uppercase tracking-wide text-ink/55">{t('light')}</p>
          {children(false)}
        </div>
        <div className="rounded-2xl bg-deep p-5">
          <p className="mb-3 text-xs font-medium uppercase tracking-wide text-white/70">{t('dark')}</p>
          {children(true)}
        </div>
      </div>
    </section>
  )
}

function CertificateSample({ dark }: { dark: boolean }) {
  const { t } = useTranslation('design')
  const ink = dark ? 'text-gold-300' : 'text-brand-900'
  return (
    <OrnamentFrame className={dark ? 'text-gold-400' : 'text-gold-500'} paper={dark ? 'var(--color-deep)' : 'var(--color-paper)'}>
      <div className={`text-center ${ink}`}>
        <p lang="ar" dir="rtl" className="font-display text-xl">بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ</p>
        <div className="mt-4 flex items-center justify-center gap-3">
          <LogoMark className="size-10" />
          <span className="font-display text-lg">{t('authority')}</span>
        </div>
        <p className="mt-6 font-display text-3xl sm:text-4xl">{t('cert_title')}</p>
        <OrnamentDivider align="center" className="mt-3 text-gold-500" />
        <div className="mt-6 flex flex-wrap items-center justify-center gap-6">
          <div className={`grid size-20 place-items-center rounded-lg border border-dashed text-xs ${dark ? 'border-gold-300/40 text-white/70' : 'border-brand-900/30 text-ink/60'}`}>{t('photo')}</div>
          <div className="max-w-md">
            <p className={dark ? 'text-white/85' : 'text-ink/75'}>{t('cert_body')}</p>
            <p className="mt-2 font-display text-2xl">حسين علي المحروس</p>
          </div>
          <div className={`grid size-20 place-items-center rounded-lg border border-dashed text-xs ${dark ? 'border-gold-300/40 text-white/70' : 'border-brand-900/30 text-ink/60'}`}>QR</div>
        </div>
      </div>
    </OrnamentFrame>
  )
}
