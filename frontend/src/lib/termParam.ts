import { api } from '../api/client'

/**
 * The term the staff shell is viewing, sent as ?term_id= on every GET (term-bound endpoints filter on it, the rest
 * ignore it). null outside the staff shell, so public and family pages send nothing. Set by TermProvider.
 */
let activeTerm: number | 'all' | null = null

export function setActiveTerm(term: number | 'all' | null) {
  activeTerm = term
}

api.interceptors.request.use((config) => {
  if (activeTerm !== null && (config.method ?? 'get').toLowerCase() === 'get') {
    const params = (config.params ?? {}) as Record<string, unknown>
    if (params.term_id === undefined) config.params = { ...params, term_id: activeTerm }
  }
  return config
})
