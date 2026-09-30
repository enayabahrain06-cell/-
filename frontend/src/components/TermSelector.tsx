import { useTranslation } from 'react-i18next'
import { useTerm } from '../app/term'
import SelectField from './SelectField'

/** The staff term selector: the current term by default, any other term, or all terms. Hidden until a term exists. */
export default function TermSelector({ className = '', id = 'term-selector' }: { className?: string; id?: string }) {
  const { t } = useTranslation('masterData')
  const { terms, term, setTerm } = useTerm()
  if (terms.length === 0) return null

  return (
    <SelectField id={id} label={t('selector.label')} hideLabel className={className} value={String(term)}
      onChange={(e) => setTerm(e.target.value === 'all' ? 'all' : Number(e.target.value))}
      options={[
        ...terms.map((x) => ({ value: String(x.id), label: x.is_current ? t('selector.current', { name: x.name }) : x.name })),
        { value: 'all', label: t('selector.all') },
      ]} />
  )
}
