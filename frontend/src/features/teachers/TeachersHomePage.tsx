import { useSearchParams } from 'react-router-dom'
import TeacherDetailView from './TeacherDetail'
import TeachersList from './TeachersList'

/** /teachers lists teachers; /teachers?teacher=<id> opens one teacher's page. */
export default function TeachersHomePage() {
  const [params] = useSearchParams()
  const id = Number(params.get('teacher'))
  return id > 0 ? <TeacherDetailView id={id} /> : <TeachersList />
}
