import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { Booking, Hall, Lesson } from '../../api/lessons'
import Icon from '../../components/Icon'
import BottomSheet from '../../components/mobile/BottomSheet'
import { Fab, StickyActionBar, StickySquare } from '../../components/mobile/ActionBars'
import { HeaderAction, MobilePage } from '../../components/mobile/MobileChrome'
import { Chip, ChipRow, MAvatar, MCard, MEmpty, MList, MListSkeleton, MRow, MSearch, MSegmented, Pill, M_BTN_PRIMARY, M_BTN_SECONDARY, M_CARD, type PillTone } from '../../components/mobile/atoms'
import { formatDate, formatNumber, formatTime } from '../../lib/format'

/** Circles and halls below lg (mobile-redesign-spec.md §6.6–6.7). Queries, filters and dialogs stay in the pages. */

const JS_DAY = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']
const todayKey = () => JS_DAY[new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Bahrain' })).getDay()]
const GENDER_PILL: Record<string, PillTone> = { male: 'ok', female: 'warn', mixed: 'info', shared: 'neutral' }

export function MobileLessonsHeader({ tab, onTab }: { tab: 'circles' | 'halls' | 'bookings'; onTab: (v: 'circles' | 'halls' | 'bookings') => void }) {
  const { t } = useTranslation('lessons')
  return (
    <div className="space-y-3 lg:hidden">
      <h1 className="font-display text-[22px] leading-7 text-brand-900">{t('title')}</h1>
      <MSegmented label={t('title')} value={tab} onChange={onTab} options={(['circles', 'halls', 'bookings'] as const).map((k) => ({ value: k, label: t(`tabs.${k}`) }))} />
    </div>
  )
}

export function MobileCircles({ lessons, total, loading, filters, showTracks, onSet, canCreate, onCreate, pagination, notice }: {
  lessons: Lesson[] | undefined; total: number | undefined; loading: boolean
  filters: { gender?: string; status?: string; search?: string }; showTracks: boolean
  onSet: (k: string, v: string) => void; canCreate: boolean; onCreate: () => void; pagination: ReactNode; notice?: ReactNode
}) {
  const { t, i18n } = useTranslation('lessons')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const [search, setSearch] = useState(filters.search ?? '')
  const [sheet, setSheet] = useState(false)
  const today = todayKey()

  return (
    <div className="space-y-3 lg:hidden">
      <div className="flex gap-2">
        <form className="min-w-0 flex-1" onSubmit={(e) => { e.preventDefault(); onSet('search', search.trim()) }}>
          <MSearch label={t('filters.search')} value={search} onChange={(v) => { setSearch(v); if (!v) onSet('search', '') }} />
        </form>
        <button type="button" onClick={() => setSheet(true)} aria-label={t('mobile.filters')} title={t('mobile.filters')}
          className="relative inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700">
          <Icon name="filter" className="size-5" />
          {filters.status && <span aria-hidden className="absolute end-2 top-2 size-2 rounded-full bg-gold-500" />}
        </button>
      </div>
      {showTracks && (
        <ChipRow label={t('filters.all_tracks')}>
          <Chip active={!filters.gender} onClick={() => onSet('gender', '')}>{t('mobile.all')}{total !== undefined && !filters.gender && <> {n(total)}</>}</Chip>
          {(['male', 'female', 'mixed'] as const).map((g) => <Chip key={g} active={filters.gender === g} onClick={() => onSet('gender', g)}>{t(`gender.${g}`)}</Chip>)}
        </ChipRow>
      )}
      {notice}

      {loading ? <MListSkeleton rows={4} /> : !lessons || lessons.length === 0 ? (
        <MCard><MEmpty icon="lessons" text={t('empty')} /></MCard>
      ) : (
        <ul className="space-y-3">
          {lessons.map((l) => {
            const meetsToday = l.days.includes(today)
            const fill = l.capacity ? Math.min(100, Math.round((l.student_count / l.capacity) * 100)) : 0
            return (
              <li key={l.id}>
                <Link to={`/lessons/${l.id}`} className={`${M_CARD} block p-4`}>
                  <div className="flex items-start justify-between gap-3">
                    <p className="min-w-0 truncate text-[15px] font-semibold text-ink"><bdi>{l.name}</bdi></p>
                    <Pill tone={meetsToday ? 'ok' : 'neutral'}>
                      {meetsToday ? t('mobile.today') : l.days.map((d) => t(`days.${d}`)).join(locale === 'ar' ? '، ' : ', ')} · {formatTime(l.start_time, locale)}
                    </Pill>
                  </div>
                  <p className="mt-1 truncate text-[13px] text-ink/65">
                    <bdi>{l.teacher?.name ?? '—'}</bdi> · <bdi>{l.location?.name ?? t('no_hall')}</bdi>
                  </p>
                  <div className="mt-3 flex items-center gap-3">
                    <div className="h-1 flex-1 overflow-hidden rounded-full bg-ink/5" aria-hidden>
                      <div className={`h-full rounded-full ${fill >= 100 ? 'bg-chart-late' : 'bg-chart-present'}`} style={{ width: `${fill}%` }} />
                    </div>
                    <span className="shrink-0 text-xs tabular-nums text-ink/65">{t('students_of', { n: n(l.student_count), c: n(l.capacity) })}</span>
                  </div>
                  {(l.status !== 'active' || l.gender) && (
                    <div className="mt-2 flex flex-wrap gap-1.5">
                      {l.gender && <Pill tone={GENDER_PILL[l.gender]}>{t(`gender.${l.gender}`)}</Pill>}
                      {l.status !== 'active' && <Pill>{t(`status.${l.status}`)}</Pill>}
                    </div>
                  )}
                </Link>
              </li>
            )
          })}
        </ul>
      )}
      {pagination}
      {canCreate && <Fab label={t('new_circle')} onClick={onCreate} />}

      <BottomSheet open={sheet} onClose={() => setSheet(false)} title={t('mobile.filters')}
        footer={<button type="button" onClick={() => setSheet(false)} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('mobile.done')}</button>}>
        <fieldset className="space-y-2">
          <legend className="mb-2 text-xs font-semibold text-ink/65">{t('mobile.status')}</legend>
          <div className="flex flex-wrap gap-2">
            {(['', 'active', 'paused', 'ended'] as const).map((s) => (
              <Chip key={s || 'all'} active={(filters.status ?? '') === s} onClick={() => onSet('status', s)}>{s ? t(`status.${s}`) : t('filters.all_statuses')}</Chip>
            ))}
          </div>
        </fieldset>
      </BottomSheet>
    </div>
  )
}

export function MobileHalls({ halls, loading, canManage, onNew, onEdit, onToggle }: { halls: Hall[] | undefined; loading: boolean; canManage: boolean; onNew: () => void; onEdit: (h: Hall) => void; onToggle: (h: Hall) => void }) {
  const { t, i18n } = useTranslation('lessons')
  const locale = i18n.language
  const btn = `${M_BTN_SECONDARY} px-3 text-[13px]`
  return (
    <div className="space-y-3 lg:hidden">
      {loading ? <MListSkeleton rows={3} /> : !halls?.length ? <MCard><MEmpty icon="pin" text={t('empty_halls')} /></MCard> : (
        <ul className="space-y-3">
          {halls.map((h) => (
            <li key={h.id} className={`${M_CARD} p-4 ${h.is_active ? '' : 'opacity-75'}`}>
              <div className="flex items-start justify-between gap-3">
                <p className="min-w-0 truncate text-[15px] font-semibold text-ink"><bdi>{h.name}</bdi></p>
                <Pill tone={GENDER_PILL[h.gender]}>{t(`gender.${h.gender}`)}</Pill>
              </div>
              <p className="mt-1 text-[13px] text-ink/65">
                {h.code && <span className="tabular-nums">{h.code} · </span>}{t('halls.capacity')}: <span className="tabular-nums">{formatNumber(h.capacity, locale)}</span>
                {h.lessons_count !== undefined && <> · {t('halls.circles', { n: formatNumber(h.lessons_count, locale) })}</>}
                {!h.is_active && <> · {t('halls.inactive')}</>}
              </p>
              <div className="mt-3 flex flex-wrap gap-2">
                <Link to={`/lessons/halls/${h.id}`} className={btn}><Icon name="attendance" className="size-4" />{t('halls.calendar')}</Link>
                {h.map_link && <a href={h.map_link} target="_blank" rel="noreferrer" className={btn}><Icon name="pin" className="size-4" />{t('halls.open_map')}</a>}
                {canManage && <button type="button" onClick={() => onEdit(h)} className={btn}><Icon name="edit" className="size-4" />{t('halls.edit')}</button>}
                {canManage && <button type="button" onClick={() => onToggle(h)} className={btn}>{h.is_active ? t('halls.toggle_off') : t('halls.toggle_on')}</button>}
              </div>
            </li>
          ))}
        </ul>
      )}
      {canManage && <Fab label={t('new_hall')} onClick={onNew} />}
    </div>
  )
}

export function MobileBookings({ bookings, loading, canManage, onNew, onDelete, pagination }: { bookings: Booking[] | undefined; loading: boolean; canManage: boolean; onNew: () => void; onDelete: (b: Booking) => void; pagination: ReactNode }) {
  const { t, i18n } = useTranslation('lessons')
  const locale = i18n.language
  return (
    <div className="space-y-3 lg:hidden">
      {loading ? <MListSkeleton rows={4} /> : !bookings?.length ? <MCard><MEmpty icon="attendance" text={t('empty_bookings')} /></MCard> : (
        <MList label={t('tabs.bookings')}>
          {bookings.map((b) => (
            <MRow key={b.id} title={<bdi>{b.title}</bdi>}
              caption={<>{formatDate(b.booking_date, locale, { weekday: 'short', day: 'numeric', month: 'short' })} · <span className="tabular-nums">{formatTime(b.start_time, locale)}–{formatTime(b.end_time, locale)}</span>{b.location && <> · <bdi>{b.location.name}</bdi></>}</>}
              trailing={canManage ? (
                <button type="button" onClick={() => onDelete(b)} aria-label={t('bookings.delete')} title={t('bookings.delete')} className="inline-grid size-11 shrink-0 place-items-center rounded-ctl text-danger">
                  <Icon name="trash" className="size-5" />
                </button>
              ) : b.gender ? <Pill tone={GENDER_PILL[b.gender]}>{t(`gender.${b.gender}`)}</Pill> : undefined} />
          ))}
        </MList>
      )}
      {pagination}
      {canManage && <Fab label={t('new_booking')} onClick={onNew} />}
    </div>
  )
}

/** /lessons/:id */
export function MobileLessonDetail({ lesson, manage, canMessage, notice, conflicts, onEdit, onChangeHall, onAdd, onUnenroll }: {
  lesson: Lesson; manage: boolean; canMessage: boolean; notice?: ReactNode; conflicts?: ReactNode
  onEdit: () => void; onChangeHall: () => void; onAdd: () => void; onUnenroll: (studentId: number, name: string) => void
}) {
  const { t, i18n } = useTranslation('lessons')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const [tab, setTab] = useState<'students' | 'schedule'>('students')
  const [menu, setMenu] = useState(false)
  const todayIso = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bahrain' }).format(new Date())
  const todaySession = (lesson.next_sessions ?? []).find((s) => s.session_date === todayIso && s.status !== 'cancelled')
  const full = lesson.student_count >= lesson.capacity
  const canAdd = !!lesson.can_add_students && !full

  return (
    <div className="space-y-4 lg:hidden">
      <MobilePage title={lesson.name} back="/lessons"
        breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('title'), to: '/lessons' }, { label: lesson.name }]}
        actions={manage ? <HeaderAction icon="more" label={t('mobile.more_actions')} onClick={() => setMenu(true)} /> : undefined} />

      <MCard>
        <div className="flex items-start justify-between gap-3">
          <p className="min-w-0 truncate text-[15px] font-semibold text-ink"><bdi>{lesson.teacher?.name ?? '—'}</bdi></p>
          <Pill tone={lesson.status === 'active' ? 'ok' : 'neutral'}>{t(`status.${lesson.status}`)}</Pill>
        </div>
        <p className="mt-1 text-[13px] text-ink/65">
          <bdi>{lesson.location?.name ?? t('no_hall')}</bdi> · {lesson.days.map((d) => t(`days.${d}`)).join(locale === 'ar' ? '، ' : ', ')} · <span className="tabular-nums">{formatTime(lesson.start_time, locale)}–{formatTime(lesson.end_time, locale)}</span>
        </p>
        {lesson.package && <p className="mt-0.5 truncate text-[13px] text-ink/65"><bdi>{lesson.package.name}</bdi></p>}
        <dl className="mt-3 grid grid-cols-3 gap-2 border-t border-ink/10 pt-3 text-center">
          <Mini label={t('mobile.students')} value={`${n(lesson.student_count)}/${n(lesson.capacity)}`} />
          <Mini label={t('mobile.seats')} value={n(Math.max(0, lesson.capacity - lesson.student_count))} />
          <Mini label={t('mobile.upcoming')} value={n((lesson.next_sessions ?? []).length)} />
        </dl>
      </MCard>

      {notice}
      {conflicts}

      <MSegmented label={lesson.name} value={tab} onChange={setTab} options={[{ value: 'students', label: t('detail.roster') }, { value: 'schedule', label: t('mobile.schedule') }]} />

      {tab === 'students' ? (
        <div className="space-y-3">
          {lesson.can_add_students && todaySession && (
            <button type="button" onClick={onAdd} disabled={full} className={`${M_BTN_SECONDARY} w-full`}><Icon name="enroll" className="size-5" />{full ? t('detail.full') : t('detail.add_student')}</button>
          )}
          {(lesson.students ?? []).length === 0 ? <MCard><MEmpty icon="students" text={t('detail.empty_roster')} /></MCard> : (
            <MList label={t('detail.roster')}>
              {lesson.students!.map((ls) => (
                <MRow key={ls.id} to={`/students/${ls.student.id}`}
                  leading={<MAvatar name={ls.student.full_name} src={ls.student.photo_url} />}
                  title={<bdi>{ls.student.full_name}</bdi>}
                  caption={ls.current_memorization ?? ls.student.progress?.surah_name ?? undefined} />
              ))}
            </MList>
          )}
          {manage && (lesson.students ?? []).length > 0 && (
            <details className="text-[13px] text-ink/65">
              <summary className="flex min-h-11 cursor-pointer list-none items-center gap-2 py-3 font-semibold text-info [&::-webkit-details-marker]:hidden"><Icon name="trash" className="size-4" />{t('detail.unenroll')}</summary>
              <MList>
                {lesson.students!.map((ls) => (
                  <MRow key={ls.id} title={<bdi>{ls.student.full_name}</bdi>} trailing={
                    <button type="button" onClick={() => onUnenroll(ls.student.id, ls.student.full_name)} className="min-h-11 shrink-0 px-2 text-[13px] font-semibold text-danger">{t('detail.unenroll')}</button>
                  } />
                ))}
              </MList>
            </details>
          )}
        </div>
      ) : (lesson.next_sessions ?? []).length === 0 ? <MCard><MEmpty icon="attendance" text={t('detail.no_upcoming')} /></MCard> : (
        <MList label={t('detail.upcoming')}>
          {lesson.next_sessions!.map((s) => (
            <MRow key={s.id} to={`/attendance/${s.id}`}
              title={formatDate(s.session_date, locale, { weekday: 'long', day: 'numeric', month: 'long' })}
              caption={<><span className="tabular-nums">{formatTime(s.start_time, locale)}</span> · <bdi>{s.location?.name ?? t('no_hall')}</bdi></>}
              trailing={s.status === 'cancelled' ? <Pill>{t('status.ended')}</Pill> : s.location_id !== lesson.location_id ? <Pill tone="info">{t('detail.hall_changed_on')}</Pill> : undefined} />
          ))}
        </MList>
      )}

      {(todaySession || canAdd || canMessage) && (
        <StickyActionBar>
          {todaySession ? (
            <Link to={`/attendance/${todaySession.id}`} className={`${M_BTN_PRIMARY} flex-1`}><Icon name="attendance" className="size-5" />{t('mobile.take_today')}</Link>
          ) : canAdd ? (
            <button type="button" onClick={onAdd} className={`${M_BTN_PRIMARY} flex-1`}><Icon name="enroll" className="size-5" />{t('detail.add_student')}</button>
          ) : <span className="flex-1" />}
          {canMessage && <StickySquare icon="messages" label={t('mobile.message')} to="/messages?tab=send" />}
        </StickyActionBar>
      )}

      <BottomSheet open={menu} onClose={() => setMenu(false)} title={t('mobile.more_actions')}>
        <MList>
          <li><button type="button" onClick={() => { setMenu(false); onEdit() }} className="flex min-h-[52px] w-full items-center gap-3 px-4 text-[15px] text-ink"><Icon name="edit" className="size-5 text-brand-700" />{t('detail.edit')}</button></li>
          <li><button type="button" onClick={() => { setMenu(false); onChangeHall() }} className="flex min-h-[52px] w-full items-center gap-3 px-4 text-[15px] text-ink"><Icon name="pin" className="size-5 text-brand-700" />{t('detail.change_location')}</button></li>
        </MList>
      </BottomSheet>
    </div>
  )
}

function Mini({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="truncate text-xs text-ink/65">{label}</dt>
      <dd className="mt-0.5 truncate text-lg font-semibold tabular-nums text-ink">{value}</dd>
    </div>
  )
}
