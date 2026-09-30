import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../app/AuthContext'
import { PageBand } from '../../components/ornaments'
import { Segmented } from '../../components/ui'
import { StaffDaySheet, StaffSummary } from './StaffAttendance'
import { useEmbed, useOwnParam } from '../../app/embed'

type Tab = 'view' | 'record'

/**
 * عرض حضور المعلمين: the term per teacher (nights they teach, attended, rate, and sessions whose attendance they took),
 * with recording a night's teachers as an action inside the same screen. Expected teachers come from the timetable.
 */
export default function TeacherAttendancePage() {
  const { t } = useTranslation('attendanceFollowup')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const host = useEmbed()
  const ownTab = useOwnParam(params, 'tab')
  const canRecord = can('staff_attendance.record')
  const tab: Tab = ownTab === 'record' && canRecord ? 'record' : 'view'

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.view_teacher_attendance')} subtitle={t(tab === 'view' ? 'staff.subtitle_view_teacher' : 'staff.subtitle_record_teacher')} />
      </div>
      {!host && canRecord && (
        <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
          <Segmented name="teacher-att-tab" label={t('nav:menu.view_teacher_attendance')} value={tab} onChange={(v) => setParams({ tab: v }, { replace: true })}
            options={[{ value: 'view', label: t('staff.tab_view') }, { value: 'record', label: t('staff.tab_record_teacher') }]} />
        </div>
      )}
      {tab === 'record' ? <StaffDaySheet kind="teacher" /> : <StaffSummary kind="teacher" />}
    </div>
  )
}
