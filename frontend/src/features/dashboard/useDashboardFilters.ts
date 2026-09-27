import { useSearchParams } from 'react-router-dom'

/**
 * Filters every dashboard section follows. There is no branch concept in the system yet, and no
 * top-bar picker; the term (packages.term) is read from ?term= so a picker can drive it later.
 * Sections pass these as query params and include them in their query keys.
 */
export function useDashboardFilters(): { term?: string } {
  const [params] = useSearchParams()
  const term = params.get('term') || undefined
  return { term }
}
