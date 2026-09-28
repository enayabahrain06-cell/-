import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { QUESTION_TYPES, examsApi, type Question, type QuestionInput, type QuestionOption, type QuestionType } from '../../api/exams'
import { parseApiError } from '../../api/client'
import SelectField from '../../components/SelectField'
import { Modal, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput, inputClass, IconButton } from '../../components/ui'

const KEYS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']

/** One dialog for all five question types; options and the answer key adapt to the type. */
export default function QuestionEditor({ examId, question, onClose, onSaved }: { examId: number; question?: Question; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation('exams')
  const [type, setType] = useState<QuestionType>(question?.type ?? 'mcq')
  const [prompt, setPrompt] = useState(question?.prompt ?? '')
  const [marks, setMarks] = useState(question?.marks ?? 10)
  const [options, setOptions] = useState<QuestionOption[]>(() => question?.options?.length ? question.options : [{ key: 'a', text: '' }, { key: 'b', text: '' }, { key: 'c', text: '' }])
  const [correctKey, setCorrectKey] = useState(question?.correct_answer?.key ?? 'a')
  const [tf, setTf] = useState<boolean>(question?.correct_answer?.value ?? true)
  const [text, setText] = useState(question?.correct_answer?.text ?? '')
  const [alts, setAlts] = useState((question?.correct_answer?.alternatives ?? []).join('\n'))
  // order_verses: options are listed in the correct order in the editor.
  const [verses, setVerses] = useState<string[]>(() => {
    if (question?.type === 'order_verses' && question.options && question.correct_answer?.order) {
      return question.correct_answer.order.map((k) => question.options!.find((o) => o.key === k)?.text ?? '')
    }
    return ['', '', '']
  })
  const [error, setError] = useState<string | null>(null)

  const payload = (): QuestionInput => {
    const base = { type, prompt, marks }
    switch (type) {
      case 'mcq': return { ...base, options: options.filter((o) => o.text.trim()), correct_answer: { key: correctKey } }
      case 'true_false': return { ...base, options: null, correct_answer: { value: tf } }
      case 'complete_verse': return { ...base, options: null, correct_answer: { text, alternatives: alts.split('\n').map((s) => s.trim()).filter(Boolean) } }
      case 'order_verses': {
        const opts = verses.map((v, i) => ({ key: KEYS[i], text: v })).filter((o) => o.text.trim())
        return { ...base, options: opts, correct_answer: { order: opts.map((o) => o.key) } }
      }
      default: return { ...base, options: null, correct_answer: null }
    }
  }

  const save = useMutation({
    mutationFn: () => (question ? examsApi.updateQuestion(examId, question.id, payload()) : examsApi.addQuestion(examId, payload())),
    onSuccess: onSaved,
    onError: (e) => { const p = parseApiError(e); setError(Object.values(p.fields)[0]?.[0] ?? p.message) },
  })

  return (
    <Modal wide title={question ? t('questions.edit') : t('questions.add')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('questions.cancel')}</SecondaryButton><PrimaryButton disabled={!prompt.trim()} loading={save.isPending} onClick={() => save.mutate()}>{t('questions.save')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="grid gap-4 sm:grid-cols-3">
        <SelectField className="sm:col-span-2" label={t('questions.type')} value={type} onChange={(e) => setType(e.target.value as QuestionType)} options={QUESTION_TYPES.map((q) => ({ value: q, label: t(`questions.types.${q}`) }))} />
        <TextInput label={t('questions.marks')} type="number" min={0} value={marks} onChange={(e) => setMarks(Number(e.target.value))} />
      </div>
      <TextArea label={t('questions.prompt')} value={prompt} onChange={(e) => setPrompt(e.target.value)} dir="auto" className="[&_textarea]:font-display [&_textarea]:text-lg" />

      {type === 'mcq' && (
        <fieldset className="space-y-2">
          <legend className="mb-1 text-sm font-medium text-ink/75">{t('questions.options')} · {t('questions.correct')}</legend>
          {options.map((o, i) => (
            <div key={o.key} className="flex items-center gap-2">
              <input type="radio" name="correct" aria-label={`${t('questions.correct')} ${o.key}`} checked={correctKey === o.key} onChange={() => setCorrectKey(o.key)} className="size-4 accent-brand-600" />
              <input aria-label={`${t('questions.options')} ${i + 1}`} dir="auto" value={o.text} onChange={(e) => setOptions(options.map((x, j) => (j === i ? { ...x, text: e.target.value } : x)))}
                className={inputClass('md', 'min-w-0 flex-1')} />
              {options.length > 2 && <IconButton icon="close" tone="remove" label={t('questions.delete')} onClick={() => setOptions(options.filter((_, j) => j !== i))} />}
            </div>
          ))}
          {options.length < KEYS.length && <button type="button" className="text-sm font-medium text-brand-700 hover:underline" onClick={() => setOptions([...options, { key: KEYS.find((k) => !options.some((o) => o.key === k))!, text: '' }])}>+ {t('questions.add_option')}</button>}
        </fieldset>
      )}

      {type === 'true_false' && (
        <fieldset className="flex gap-3">
          <legend className="mb-1 text-sm font-medium text-ink/75">{t('questions.correct')}</legend>
          {[true, false].map((v) => (
            <label key={String(v)} className={`cursor-pointer rounded-xl border px-4 py-2 text-sm ${tf === v ? 'border-brand-600 bg-brand-50 font-medium' : 'border-ink/15'}`}>
              <input type="radio" className="sr-only" checked={tf === v} onChange={() => setTf(v)} />{v ? t('questions.true') : t('questions.false')}
            </label>
          ))}
        </fieldset>
      )}

      {type === 'complete_verse' && (
        <>
          <TextArea label={t('questions.answer_text')} rows={2} value={text} onChange={(e) => setText(e.target.value)} dir="rtl" className="[&_textarea]:font-quran [&_textarea]:text-lg" />
          <TextArea label={t('questions.alternatives')} rows={2} value={alts} onChange={(e) => setAlts(e.target.value)} dir="rtl" />
        </>
      )}

      {type === 'order_verses' && (
        <fieldset className="space-y-2">
          <legend className="mb-1 text-sm font-medium text-ink/75">{t('questions.order_hint')}</legend>
          {verses.map((v, i) => (
            <div key={i} className="flex items-center gap-2">
              <span className="w-6 text-center text-sm tabular-nums text-ink/50">{i + 1}</span>
              <input aria-label={`${i + 1}`} dir="rtl" value={v} onChange={(e) => setVerses(verses.map((x, j) => (j === i ? e.target.value : x)))} className={inputClass('md', 'min-w-0 flex-1 font-quran text-lg')} />
              {verses.length > 2 && <IconButton icon="close" tone="remove" label={t('questions.delete')} onClick={() => setVerses(verses.filter((_, j) => j !== i))} />}
            </div>
          ))}
          {verses.length < KEYS.length && <button type="button" className="text-sm font-medium text-brand-700 hover:underline" onClick={() => setVerses([...verses, ''])}>+ {t('questions.add_option')}</button>}
        </fieldset>
      )}

      {type === 'recitation' && <Notice tone="info">{t('questions.recitation_hint')}</Notice>}
    </Modal>
  )
}
