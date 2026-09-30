import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { activitiesApi, type Activity, type ActivityOptions, type ActivityType } from '../../api/activities'
import { useAuth } from '../../app/AuthContext'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { EmptyCard, FilterBar, LoadingState, Segmented } from '../../components/ui'
import { QueryError } from '../attendanceFollowup/shared'
import ActivityList from './ActivityList'
import AttendanceTab, { AttendanceFollowupTab } from './AttendanceTabs'
import RegisterTab from './RegisterTab'
import { BookDeliveryTab, BookFollowupTab, EvaluationTab, EvaluationViewTab, FeeFollowupTab, FeePaymentTab, StudentsTab } from './RosterTabs'
import LinkedAlbums from '../gallery/LinkedAlbums'
import { ActivityWhen, TABS, type Tab } from './shared'

/**
 * البرامج / الرحلات: one page per type on the one activities engine. Each menu entry of the section opens its tab
 * (?tab=); every tab but the list works on one program or trip, chosen once and kept in ?activity=.
 */
export default function ActivitiesPage({ type }: { type: ActivityType }) {
  const { t } = useTranslation('activities')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const tabs = TABS[type].filter((x) => can(...x.perms))
  const current = tabs.find((x) => x.tab === params.get('tab')) ?? tabs[0]
  const q = useQuery({ queryKey: ['activities', type], queryFn: () => activitiesApi.list(type) })
  const set = (patch: Record<string, string>) => setParams((p) => { const n = new URLSearchParams(p); Object.entries(patch).forEach(([k, v]) => n.set(k, v)); return n }, { replace: true })
  if (!current) return null
  const title = t(`nav:menu.${current.menu}`)
  const options = tabs.map((x) => ({ value: x.tab, label: t(`nav:menu.${x.menu}`) }))

  return (
    <div className="space-y-5">
      <div className="hidden lg:block"><PageBand title={title} subtitle={q.data ? t(`subtitle.${type}`, { term: q.data.term.name }) : undefined} /></div>
      {tabs.length > 1 && (
        <>
          <div className="hidden lg:block">
            <Segmented name={`${type}-tab`} label={t(`nav:menu.${TABS[type][0].menu}`)} value={current.tab} onChange={(v) => set({ tab: v })} options={options} />
          </div>
          <div className="lg:hidden">
            <SelectField label={t('screen')} value={current.tab} onChange={(e) => set({ tab: e.target.value })} options={options} />
          </div>
        </>
      )}
      {q.isLoading ? <LoadingState /> : q.isError ? <QueryError error={q.error} onRetry={() => void q.refetch()} /> : q.data && (
        current.tab === 'list'
          ? <ActivityList type={type} data={q.data} onOpen={(a) => set({ activity: String(a.id), tab: tabs.find((x) => x.tab !== 'list')?.tab ?? 'list' })} />
          : <Picked type={type} tab={current.tab} list={q.data.data} options={q.data.options} activityId={Number(params.get('activity')) || null} onPick={(id) => set({ activity: String(id) })} />
      )}
    </div>
  )
}

/** Where the activity's photo albums show: the program's students, the trip's after-trip attendance follow-up. */
const ALBUMS_TAB: Record<ActivityType, Tab> = { program: 'students', trip: 'attendance_followup' }

function Picked({ type, tab, list, options, activityId, onPick }: { type: ActivityType; tab: Tab; list: Activity[]; options: ActivityOptions; activityId: number | null; onPick: (id: number) => void }) {
  const { t } = useTranslation('activities')
  if (list.length === 0) return <EmptyCard icon={type === 'trip' ? 'pin' : 'trophy'} title={t(`empty.${type}`)} />
  const activity = list.find((a) => a.id === activityId) ?? list.find((a) => a.status === 'open') ?? list[0]

  return (
    <div className="space-y-4">
      <FilterBar label={t('filters')}>
        <SelectField label={t(`pick.${type}`)} className="sm:w-80" value={String(activity.id)} onChange={(e) => onPick(Number(e.target.value))}
          options={list.map((a) => ({ value: String(a.id), label: a.status === 'open' ? a.name : t('option_status', { name: a.name, status: t(`status.${a.status}`) }) }))} />
        <p className="text-sm text-ink/60 sm:self-center"><ActivityWhen a={activity} /></p>
      </FilterBar>
      <TabBody key={`${tab}-${activity.id}`} tab={tab} activity={activity} options={options} />
      {ALBUMS_TAB[type] === tab && <LinkedAlbums type="activity" id={activity.id} />}
    </div>
  )
}

function TabBody({ tab, activity, options }: { tab: Tab; activity: Activity; options: ActivityOptions }) {
  switch (tab) {
    case 'register': return <RegisterTab activity={activity} classes={options.classes} />
    case 'students': return <StudentsTab activity={activity} />
    case 'fee_payment': return <FeePaymentTab activity={activity} />
    case 'fee_followup': return <FeeFollowupTab activity={activity} />
    case 'book_delivery': return <BookDeliveryTab activity={activity} />
    case 'book_followup': return <BookFollowupTab activity={activity} />
    case 'attendance': return <AttendanceTab activity={activity} />
    case 'attendance_followup': return <AttendanceFollowupTab activity={activity} />
    case 'evaluation': return <EvaluationTab activity={activity} />
    case 'evaluation_view': return <EvaluationViewTab activity={activity} />
    default: return null
  }
}
