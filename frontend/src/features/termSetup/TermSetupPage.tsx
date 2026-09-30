import { isAxiosError } from 'axios'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { PageBand } from '../../components/ornaments'
import { ErrorState, LoadingState, Notice, Segmented } from '../../components/ui'
import { parseApiError } from '../../api/client'
import LevelRoomsTab from './LevelRoomsTab'
import LevelSubjectsTab from './LevelSubjectsTab'
import { PlanTab, PlanViewTab } from './PlanTab'
import SubjectLessonsTab from './SubjectLessonsTab'
import SupervisorsTab from './SupervisorsTab'
import TimetableTab from './TimetableTab'
import { TermBar, useSetupOptions } from './shared'
import { useEmbed, useOwnParam } from '../../app/embed'

/** In the order and under the names of the other system's اعدادات الفصل menu. */
const TABS = ['level_rooms', 'level_subjects', 'subject_lessons', 'plan', 'plan_view', 'supervisors', 'timetable'] as const
type Tab = (typeof TABS)[number]

/** اعدادات الفصل: subjects per level, curriculum lessons, plan, night supervisors and timetable of the selected term. */
export default function TermSetupPage() {
  const { t } = useTranslation('termSetup')
  const [params, setParams] = useSearchParams()
  const host = useEmbed()
  const ownTab = useOwnParam(params, 'tab')
  const tab: Tab = (TABS as readonly string[]).includes(ownTab ?? '') ? (ownTab as Tab) : 'level_rooms'
  const options = useSetupOptions()

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('title')} subtitle={t('subtitle')} />
      </div>
      {!host && <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
        <Segmented name="term-setup-tab" label={t('title')} value={tab}
          options={TABS.map((k) => ({ value: k, label: t(`tabs.${k}`) }))}
          onChange={(v) => setParams({ tab: v }, { replace: true })} />
      </div>}
      {options.isLoading ? <LoadingState /> : options.isError ? (
        // 422 when no term exists yet: say so instead of a generic error.
        isAxiosError(options.error) && options.error.response?.status === 422
          ? <Notice tone="info">{parseApiError(options.error).message}</Notice>
          : <ErrorState onRetry={() => void options.refetch()} />
      ) : (
        <div className="space-y-4">
          <TermBar />
          {tab === 'level_rooms' && <LevelRoomsTab />}
          {tab === 'level_subjects' && <LevelSubjectsTab />}
          {tab === 'subject_lessons' && <SubjectLessonsTab />}
          {tab === 'plan' && <PlanTab />}
          {tab === 'plan_view' && <PlanViewTab />}
          {tab === 'supervisors' && <SupervisorsTab />}
          {tab === 'timetable' && <TimetableTab />}
        </div>
      )}
    </div>
  )
}
