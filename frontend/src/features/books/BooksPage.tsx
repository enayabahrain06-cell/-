import { useState } from 'react'
import { isAxiosError } from 'axios'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { booksApi, type Book, type BookInput, type BookOptions } from '../../api/books'
import { parseApiError, type FieldErrors } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, IconButton, LoadingState, Modal, Notice, PrimaryButton, Segmented, SURFACE, TABLE_HEAD, TableWrap, TextArea, TextInput } from '../../components/ui'
import { formatDate, formatMoney, formatNumber } from '../../lib/format'
import { toLatinDigits } from '../../lib/phone'
import { DialogFooter, Field, Toolbar, useRemove } from '../common/crud'
import { useEmbed, useOwnParam } from '../../app/embed'
import { useTermScope } from '../../app/term'

type Tab = 'books' | 'followup'

/** الكتب (the term's books) and متابعة الكتب (who received them). */
export default function BooksPage() {
  const { t } = useTranslation('books')
  const ts = useTermScope()
  const [params, setParams] = useSearchParams()
  const host = useEmbed()
  const ownTab = useOwnParam(params, 'tab')
  const tab: Tab = ownTab === 'followup' ? 'followup' : 'books'
  const q = useQuery({ queryKey: ['books'], queryFn: booksApi.list })

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.books')} subtitle={q.data ? t('subtitle', { term: ts.label(q.data.term.name) }) : undefined} />
      </div>
      {!host && <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
        <Segmented name="books-tab" label={t('nav:menu.books')} value={tab} onChange={(v) => setParams({ tab: v }, { replace: true })}
          options={[{ value: 'books', label: t('nav:menu.books') }, { value: 'followup', label: t('nav:menu.books_followup') }]} />
      </div>}
      {q.isLoading ? <LoadingState /> : q.isError ? (
        isAxiosError(q.error) && q.error.response?.status === 422 ? <Notice tone="info">{parseApiError(q.error).message}</Notice> : <ErrorState onRetry={() => void q.refetch()} />
      ) : q.data && (tab === 'books'
        ? <BooksTab books={q.data.data} termId={q.data.term.id} options={q.data.options} />
        : <FollowupTab books={q.data.data} options={q.data.options} />)}
    </div>
  )
}

function BooksTab({ books, termId, options }: { books: Book[]; termId: number; options: BookOptions }) {
  const { t, i18n } = useTranslation('books')
  const ts = useTermScope()
  const { can } = useAuth()
  const canManage = can('books.manage')
  const [edit, setEdit] = useState<Book | 'new' | null>(null)
  const { notice, setNotice, remove } = useRemove(booksApi.remove, [['books']], t('delete_confirm'))

  return (
    <div className="space-y-4">
      <Toolbar label={canManage ? t('new') : undefined} onAdd={canManage ? () => setEdit('new') : undefined} notice={notice} />
      {books.length === 0 ? <EmptyCard icon="lessons" title={t('empty', { scope: ts.scope() })} /> : (
        <div className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
          {books.map((b) => (
            <article key={b.id} className={`${SURFACE} space-y-2 p-4 ${b.is_active ? '' : 'opacity-60'}`}>
              <div className="flex items-start gap-2">
                <h3 dir="auto" className="min-w-0 flex-1 font-semibold text-ink">{b.title}</h3>
                {canManage && (
                  <div className="flex shrink-0">
                    <IconButton icon="edit" label={t('edit')} onClick={() => setEdit(b)} />
                    <IconButton icon="trash" tone="danger" label={t('delete')} onClick={() => remove(b.id)} />
                  </div>
                )}
              </div>
              <div className="flex flex-wrap gap-1.5">
                <Badge tone={b.level ? 'info' : 'muted'}>{b.level?.name ?? t('all_levels')}</Badge>
                {b.subject && <Badge>{b.subject.name}</Badge>}
                {!b.is_active && <Badge>{t('inactive')}</Badge>}
              </div>
              <dl className="grid grid-cols-3 gap-2 text-sm">
                <div><dt className="text-xs text-ink/55">{t('fields.price')}</dt><dd className="tabular-nums">{b.price_fils > 0 ? formatMoney(b.price_fils, i18n.language) : t('free')}</dd></div>
                <div><dt className="text-xs text-ink/55">{t('fields.stock')}</dt><dd className="tabular-nums">{b.stock === null ? '—' : formatNumber(b.stock, i18n.language)}</dd></div>
                <div><dt className="text-xs text-ink/55">{t('delivered_count')}</dt><dd className="tabular-nums">{formatNumber(b.deliveries_count ?? 0, i18n.language)}</dd></div>
              </dl>
              {b.notes && <p dir="auto" className="text-sm text-ink/60">{b.notes}</p>}
            </article>
          ))}
        </div>
      )}
      {edit && <BookDialog row={edit === 'new' ? undefined : edit} termId={termId} options={options} onClose={() => setEdit(null)} onSaved={(m) => { setEdit(null); setNotice({ tone: 'success', text: m }) }} />}
    </div>
  )
}

function BookDialog({ row, termId, options, onClose, onSaved }: { row?: Book; termId: number; options: BookOptions; onClose: () => void; onSaved: (m: string) => void }) {
  const { t } = useTranslation('books')
  const qc = useQueryClient()
  const [form, setForm] = useState({
    title: row?.title ?? '', subject_id: row?.subject?.id ?? null as number | null, level_id: row?.level?.id ?? null as number | null,
    price: row ? (row.price_fils / 1000).toFixed(3) : '', stock: row?.stock === null || row?.stock === undefined ? '' : String(row.stock),
    is_active: row?.is_active ?? true, notes: row?.notes ?? '',
  })
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => {
      const price = Number(toLatinDigits(form.price || '0'))
      const d: BookInput = {
        academic_term_id: row?.academic_term_id ?? termId, title: form.title, subject_id: form.subject_id, level_id: form.level_id,
        price_fils: Number.isFinite(price) ? Math.round(price * 1000) : -1, stock: form.stock === '' ? null : Number(toLatinDigits(form.stock)),
        is_active: form.is_active, notes: form.notes || null,
      }
      return row ? booksApi.update(row.id, d) : booksApi.create(d)
    },
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['books'] }); onSaved(r.message) },
    onError: (e) => setErrors(parseApiError(e).fields),
  })

  return (
    <Modal title={row ? t('title_edit') : t('title_new')} onClose={onClose} footer={<DialogFooter onCancel={onClose} onSave={() => save.mutate()} saving={save.isPending} />}>
      <Field error={errors.title?.[0]}><TextInput label={t('fields.title')} dir="auto" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField label={t('fields.level')} value={String(form.level_id ?? '')} onChange={(e) => setForm({ ...form, level_id: e.target.value ? Number(e.target.value) : null })}
          options={[{ value: '', label: t('all_levels') }, ...options.levels.map((l) => ({ value: String(l.id), label: l.name }))]} />
        <SelectField label={t('fields.subject')} value={String(form.subject_id ?? '')} onChange={(e) => setForm({ ...form, subject_id: e.target.value ? Number(e.target.value) : null })}
          options={[{ value: '', label: t('no_subject') }, ...options.subjects.map((s) => ({ value: String(s.id), label: s.name }))]} />
        <Field error={errors.price_fils?.[0]}><TextInput label={t('fields.price')} inputMode="decimal" dir="ltr" placeholder="0.000" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} /></Field>
        <Field error={errors.stock?.[0]}><TextInput label={t('fields.stock')} inputMode="numeric" dir="ltr" value={form.stock} onChange={(e) => setForm({ ...form, stock: e.target.value })} /></Field>
      </div>
      <TextArea label={t('fields.notes')} rows={2} dir="auto" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
      <label className="flex items-center gap-2 text-sm text-ink/80">
        <input type="checkbox" className="size-4 accent-brand-700" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
        {t('fields.is_active')}
      </label>
    </Modal>
  )
}

function FollowupTab({ books, options }: { books: Book[]; options: BookOptions }) {
  const { t, i18n } = useTranslation('books')
  const ts = useTermScope()
  const locale = i18n.language
  const { can } = useAuth()
  const canManage = can('books.manage')
  const qc = useQueryClient()
  const [bookId, setBookId] = useState<number | ''>(books[0]?.id ?? '')
  const [lessonId, setLessonId] = useState('')
  const [delivered, setDelivered] = useState<'all' | 'yes' | 'no'>('all')
  const [picked, setPicked] = useState<number[]>([])
  const [charge, setCharge] = useState(false)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const book = books.find((b) => b.id === bookId)
  const q = useQuery({
    queryKey: ['book-followup', bookId, lessonId, delivered],
    queryFn: () => booksApi.followup(Number(bookId), { lesson_id: lessonId ? Number(lessonId) : undefined, delivered: delivered === 'all' ? undefined : delivered }),
    enabled: bookId !== '', placeholderData: keepPreviousData,
  })
  const rows = q.data?.data ?? []
  const open = rows.filter((r) => !r.delivery)
  const onError = (e: unknown) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) }
  const refresh = () => ['book-followup', 'books', 'payments', 'invoices', 'student-wallet'].forEach((k) => void qc.invalidateQueries({ queryKey: [k] }))
  const deliver = useMutation({
    mutationFn: () => booksApi.deliver(Number(bookId), { student_ids: picked, charge }),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); setPicked([]); refresh() },
    onError,
  })
  const undo = useMutation({
    mutationFn: (deliveryId: number) => booksApi.undo(Number(bookId), deliveryId),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); refresh() },
    onError,
  })
  const classes = options.classes.filter((c) => !book?.level || c.level_id === book.level.id)
  const allPicked = open.length > 0 && open.every((r) => picked.includes(r.student.id))

  if (books.length === 0) return <EmptyCard icon="lessons" title={t('empty', { scope: ts.scope() })} />

  return (
    <div className="space-y-4">
      <FilterBar label={t('filters')}>
        <SelectField label={t('book')} hideLabel className="sm:w-64" value={String(bookId)} onChange={(e) => { setBookId(Number(e.target.value)); setPicked([]); setLessonId('') }}
          options={books.map((b) => ({ value: String(b.id), label: b.title }))} />
        <SelectField label={t('class')} hideLabel className="sm:w-52" value={lessonId} onChange={(e) => { setLessonId(e.target.value); setPicked([]) }}
          options={[{ value: '', label: t('all_classes') }, ...classes.map((c) => ({ value: String(c.id), label: c.name }))]} />
        <Segmented name="book-delivered" label={t('filters')} value={delivered} onChange={(v) => { setDelivered(v); setPicked([]) }}
          options={[{ value: 'all', label: t('show.all') }, { value: 'no', label: t('show.no') }, { value: 'yes', label: t('show.yes') }]} />
      </FilterBar>
      {q.data && (
        <p className="text-sm text-ink/65">{t('followup_line', { delivered: formatNumber(q.data.totals.delivered, locale), expected: formatNumber(q.data.totals.expected, locale), count: q.data.totals.expected })}</p>
      )}
      {canManage && (
        <section className={`${SURFACE} flex flex-wrap items-center gap-3 p-4`} aria-label={t('deliver')}>
          <label className="flex items-center gap-2 text-sm text-ink/80">
            <input type="checkbox" className="size-4 accent-brand-700" disabled={!book || book.price_fils <= 0} checked={charge} onChange={(e) => setCharge(e.target.checked)} />
            {book && book.price_fils > 0 ? t('charge', { price: formatMoney(book.price_fils, locale) }) : t('charge_free')}
          </label>
          <PrimaryButton className="ms-auto" disabled={picked.length === 0} loading={deliver.isPending} onClick={() => deliver.mutate()}>
            {t('deliver_run', { count: picked.length, n: formatNumber(picked.length, locale) })}
          </PrimaryButton>
        </section>
      )}
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : rows.length === 0 ? <EmptyCard icon="students" title={t('no_students')} /> : (
        <TableWrap surface>
          <table className="w-full min-w-[40rem] text-sm">
            <thead className={TABLE_HEAD}>
              <tr>
                {canManage && (
                  <th scope="col" className="w-10 px-3 py-2">
                    <input type="checkbox" className="size-4 accent-brand-700" aria-label={t('pick_all')} checked={allPicked} onChange={() => setPicked(allPicked ? [] : open.map((r) => r.student.id))} />
                  </th>
                )}
                <th scope="col" className="px-3 py-2 text-start font-medium">{t('student')}</th>
                <th scope="col" className="px-3 py-2 text-start font-medium">{t('class')}</th>
                <th scope="col" className="px-3 py-2 text-start font-medium">{t('delivery')}</th>
                <th scope="col" className="px-3 py-2 text-start font-medium">{t('payment')}</th>
                {canManage && <th scope="col" className="px-3 py-2"><span className="sr-only">{t('actions')}</span></th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-ink/6">
              {rows.map((r) => {
                const inv = r.delivery?.invoice
                const paid = inv ? inv.paid_fils >= inv.amount_fils : false
                return (
                  <tr key={r.student.id}>
                    {canManage && (
                      <td className="px-3 py-2">
                        {!r.delivery && <input type="checkbox" className="size-4 accent-brand-700" aria-label={r.student.full_name} checked={picked.includes(r.student.id)}
                          onChange={() => setPicked((ps) => (ps.includes(r.student.id) ? ps.filter((x) => x !== r.student.id) : [...ps, r.student.id]))} />}
                      </td>
                    )}
                    <td className="px-3 py-2"><span dir="auto" className="block font-medium text-ink">{r.student.full_name}</span><span className="block text-xs tabular-nums text-ink/50">{r.student.student_no}</span></td>
                    <td className="px-3 py-2 text-ink/70">{r.lesson.name}</td>
                    <td className="px-3 py-2">
                      {r.delivery ? <><Badge tone="brand">{t('delivered')}</Badge>{r.delivery.delivered_at && <span className="ms-2 text-xs text-ink/55">{formatDate(r.delivery.delivered_at, locale, { day: 'numeric', month: 'short' })}</span>}</> : <Badge tone="gold">{t('not_delivered')}</Badge>}
                    </td>
                    <td className="px-3 py-2">
                      {inv ? <Badge tone={inv.status === 'cancelled' ? 'muted' : paid ? 'brand' : inv.paid_fils > 0 ? 'gold' : 'danger'}>{t(`invoice.${inv.status === 'cancelled' ? 'cancelled' : paid ? 'paid' : inv.paid_fils > 0 ? 'partial' : 'unpaid'}`)}</Badge> : <span className="text-ink/40">—</span>}
                    </td>
                    {canManage && (
                      <td className="px-3 py-2 text-end">
                        {r.delivery && <IconButton icon="refresh" label={t('undo')} disabled={undo.isPending || (inv ? inv.paid_fils > 0 : false)} onClick={() => { if (window.confirm(t('undo_confirm'))) undo.mutate(r.delivery!.id) }} />}
                      </td>
                    )}
                  </tr>
                )
              })}
            </tbody>
          </table>
        </TableWrap>
      )}
    </div>
  )
}
