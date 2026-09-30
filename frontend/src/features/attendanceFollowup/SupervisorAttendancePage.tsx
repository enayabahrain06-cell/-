import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../app/AuthContext'
import { PageBand } from '../../components/ornaments'
import { Segmented } from '../../components/ui'
import { StaffDaySheet, StaffSummary } from './StaffAttendance'

type Tab = 'record' | 'view'

/** حضور المشرفين / عرض حضور المشرفين: record a night's supervisors (مشرفو الليالي) and view the term per supervisor. */
export default function SupervisorAttendancePage() {
  const { t } = useTranslation('attendanceFollowup')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const canRecord = can('staff_attendance.record')
  const tab: Tab = params.get('tab') === 'view' || !canRecord ? 'view' : 'record'

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t(`nav:menu.${tab === 'view' ? 'view_supervisor_attendance' : 'supervisor_attendance'}`)} subtitle={t(tab === 'view' ? 'staff.subtitle_view_supervisor' : 'staff.subtitle_record_supervisor')} />
      </div>
      {canRecord && (
        <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
          <Segmented name="supervisor-att-tab" label={t('nav:supervisor_attendance')} value={tab} onChange={(v) => setParams({ tab: v }, { replace: true })}
            options={[{ value: 'record', label: t('nav:menu.supervisor_attendance') }, { value: 'view', label: t('nav:menu.view_supervisor_attendance') }]} />
        </div>
      )}
      {tab === 'record' ? <StaffDaySheet kind="supervisor" /> : <StaffSummary kind="supervisor" />}
    </div>
  )
}
