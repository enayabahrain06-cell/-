import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { termsApi, type AcademicTerm } from '../api/masterData'
import { setActiveTerm } from '../lib/termParam'
import { useAuth } from './AuthContext'

/**
 * The staff term selector (الفصل الدراسي). The chosen term travels as ?term_id= on every GET the staff shell makes;
 * endpoints that are term-bound filter on it and the rest ignore it. Default: the current term; "all" shows every
 * term, so no data is ever out of reach. The choice is a per-viewer convenience kept in localStorage.
 */
export type TermChoice = number | 'all'

const STORAGE_KEY = 'ahl.term'

function readStored(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

function writeStored(v: TermChoice) {
  try {
    localStorage.setItem(STORAGE_KEY, String(v))
  } catch {
    /* storage unavailable */
  }
}

interface TermState {
  terms: AcademicTerm[]
  /** The effective choice after validation (a stored id that no longer exists falls back to the current term). */
  term: TermChoice
  current: AcademicTerm | null
  /** The chosen term object, or null for "all terms". */
  selected: AcademicTerm | null
  setTerm: (t: TermChoice) => void
  /** False until the term list has loaded, so pages never fetch unscoped first and then again. */
  ready: boolean
}

const TermContext = createContext<TermState | null>(null)

export function TermProvider({ children }: { children: ReactNode }) {
  const { can } = useAuth()
  const qc = useQueryClient()
  const allowed = can('dashboard.view', 'terms.manage')
  const q = useQuery({ queryKey: ['academic-terms'], queryFn: termsApi.list, enabled: allowed, staleTime: 5 * 60_000 })
  const [stored, setStored] = useState<string | null>(readStored)

  const terms = useMemo(() => q.data?.data ?? [], [q.data])
  const current = terms.find((t) => t.is_current) ?? null
  const term: TermChoice = stored === 'all'
    ? 'all'
    : terms.some((t) => String(t.id) === stored) ? Number(stored) : current?.id ?? 'all'
  const ready = !allowed || q.isSuccess || q.isError

  // Set during render so the children's first queries already carry the term.
  setActiveTerm(!allowed || q.isError ? null : term)
  useEffect(() => () => setActiveTerm(null), [])

  const setTerm = useCallback((next: TermChoice) => {
    writeStored(next)
    setStored(String(next))
    setActiveTerm(next)
    // Every cached list may belong to the old term; the term list itself does not.
    void qc.invalidateQueries({ predicate: (query) => query.queryKey[0] !== 'academic-terms' })
  }, [qc])

  const value = useMemo<TermState>(() => ({
    terms, term, current, selected: term === 'all' ? null : terms.find((t) => t.id === term) ?? null, setTerm, ready,
  }), [terms, term, current, setTerm, ready])

  return <TermContext.Provider value={value}>{children}</TermContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useTerm(): TermState {
  const ctx = useContext(TermContext)
  if (!ctx) throw new Error('useTerm must be used inside TermProvider')
  return ctx
}
