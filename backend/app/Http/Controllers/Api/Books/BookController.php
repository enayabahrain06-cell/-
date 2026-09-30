<?php

namespace App\Http\Controllers\Api\Books;

use App\Enums\LessonStudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Book;
use App\Models\BookDelivery;
use App\Models\Invoice;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\Subject;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletService;
use App\Support\TeacherScope;
use App\Support\TermScope;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @group Registration
 * @subgroup Books
 *
 * الكتب (the term's books) and متابعة الكتب (who received each book). books.view reads, books.manage writes.
 * Charging the price creates an ordinary invoice (WalletService::createInvoice); the delivery keeps its id.
 */
class BookController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $term = TermScope::single($request);
        $books = Book::with(['subject', 'level'])->withCount('deliveries')->where('academic_term_id', $term->id)->orderBy('title')->get();

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'data' => $books->map(fn ($b) => self::book($b)),
            'options' => [
                'subjects' => Subject::where('is_active', true)->ordered()->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name()]),
                'levels' => \App\Models\Level::where('is_active', true)->ordered()->get()->map(fn ($l) => ['id' => $l->id, 'name' => $l->name()]),
                'classes' => $this->termLessons($request, $term)->with('level')->orderBy('name')->get()->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'level_id' => $l->level_id]),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $book = Book::create($this->validated($request));
        $this->audit->record('book.created', $book, [], $book->only(['title', 'academic_term_id', 'price_fils']));

        return response()->json(['message' => __('books.saved'), 'data' => self::book($book->load(['subject', 'level'])->loadCount('deliveries'))], 201);
    }

    public function update(Request $request, Book $book): JsonResponse
    {
        $this->authorizeManage($request);
        $old = $book->only(['title', 'price_fils', 'level_id', 'subject_id', 'stock', 'is_active']);
        $book->update($this->validated($request, $book));
        $this->audit->record('book.updated', $book, $old, $book->only(array_keys($old)));

        return response()->json(['message' => __('books.saved'), 'data' => self::book($book->fresh(['subject', 'level'])->loadCount('deliveries'))]);
    }

    public function destroy(Request $request, Book $book): JsonResponse
    {
        $this->authorizeManage($request);
        if ($book->deliveries()->exists()) {
            throw ValidationException::withMessages(['book' => __('books.errors.has_deliveries')]);
        }
        $this->audit->record('book.deleted', $book, $book->only(['title', 'academic_term_id']), []);
        $book->delete();

        return response()->json(['message' => __('books.deleted')]);
    }

    /**
     * متابعة الكتب: the students expected to get the book (the book's level's classes in its term, or every class of
     * the term when it has no level), delivered or not, with the paid state of the invoice when the price was charged.
     */
    public function followup(Request $request, Book $book): JsonResponse
    {
        $this->authorizeView($request);
        $data = $request->validate(['lesson_id' => ['nullable', 'integer'], 'level_id' => ['nullable', 'integer'], 'delivered' => ['nullable', 'in:yes,no']]);
        $term = AcademicTerm::findOrFail($book->academic_term_id);
        $lessonIds = $this->termLessons($request, $term)
            ->when($book->level_id, fn ($q) => $q->where('level_id', $book->level_id))
            ->when(! $book->level_id && ($data['level_id'] ?? null), fn ($q) => $q->where('level_id', $data['level_id']))
            ->when($data['lesson_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->pluck('id');

        $rows = LessonStudent::with(['student', 'lesson:id,name,level_id'])->whereIn('lesson_id', $lessonIds)
            ->where('status', LessonStudentStatus::Active->value)
            ->whereHas('student', fn ($q) => Track::scope($q, $request->user()))
            ->get()->unique('student_id');
        $deliveries = BookDelivery::with(['invoice', 'deliverer:id,name'])->where('book_id', $book->id)->get()->keyBy('student_id');

        $list = $rows->map(fn (LessonStudent $r) => [
            'student' => ['id' => $r->student->id, 'full_name' => $r->student->full_name, 'student_no' => $r->student->student_no, 'gender' => $r->student->gender?->value],
            'lesson' => ['id' => $r->lesson->id, 'name' => $r->lesson->name],
            'delivery' => ($d = $deliveries->get($r->student_id)) ? self::delivery($d) : null,
        ])->when(($data['delivered'] ?? null) === 'yes', fn ($c) => $c->filter(fn ($r) => $r['delivery'] !== null))
            ->when(($data['delivered'] ?? null) === 'no', fn ($c) => $c->filter(fn ($r) => $r['delivery'] === null))
            ->sortBy(fn ($r) => [$r['lesson']['name'], $r['student']['full_name']])->values();

        return response()->json([
            'book' => self::book($book->load(['subject', 'level'])->loadCount('deliveries')),
            'data' => $list,
            'totals' => ['expected' => $rows->count(), 'delivered' => $rows->filter(fn ($r) => $deliveries->has($r->student_id))->count(), 'all_deliveries' => $deliveries->count()],
        ]);
    }

    /** Hand the book to students (already delivered ones are skipped); charge=true bills the price as an invoice. */
    public function deliver(Request $request, Book $book, WalletService $wallets): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:300'],
            'student_ids.*' => ['integer', 'exists:students,id'],
            'charge' => ['boolean'],
            'delivered_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $charge = (bool) ($data['charge'] ?? false);
        if ($charge && $book->price_fils <= 0) {
            throw ValidationException::withMessages(['charge' => __('books.errors.no_price')]);
        }
        $already = BookDelivery::where('book_id', $book->id)->pluck('student_id')->all();
        $students = Student::whereIn('id', array_diff($data['student_ids'], $already))->get()
            ->filter(fn (Student $s) => Track::allows($request->user(), $s->gender));
        if ($book->stock !== null && count($already) + $students->count() > $book->stock) {
            throw ValidationException::withMessages(['student_ids' => __('books.errors.stock', ['left' => max(0, $book->stock - count($already))])]);
        }
        $on = Carbon::parse($data['delivered_at'] ?? today());

        DB::transaction(function () use ($students, $book, $charge, $on, $data, $wallets, $request) {
            foreach ($students as $s) {
                $invoice = $charge
                    ? $wallets->createInvoice($s, null, $book->price_fils, $on, __('books.invoice_description', ['title' => $book->title], 'ar'), null, $request->user()->id, $book->academic_term_id)
                    : null;
                $d = BookDelivery::create(['book_id' => $book->id, 'student_id' => $s->id, 'delivered_at' => $on->toDateString(),
                    'delivered_by' => $request->user()->id, 'invoice_id' => $invoice?->id, 'notes' => $data['notes'] ?? null]);
                $this->audit->record('book.delivered', $d, [], ['book_id' => $book->id, 'student_id' => $s->id, 'invoice_id' => $invoice?->id]);
            }
        });

        return response()->json(['message' => __('books.delivered', ['count' => $students->count()]), 'delivered' => $students->count()]);
    }

    /** Undo a delivery; its invoice is cancelled, which is refused once anything was paid on it. */
    public function undo(Request $request, Book $book, BookDelivery $delivery, WalletService $wallets): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless($delivery->book_id === $book->id, 404);
        $invoice = $delivery->invoice_id ? Invoice::find($delivery->invoice_id) : null;
        if ($invoice && $invoice->paid_fils > 0) {
            throw ValidationException::withMessages(['delivery' => __('books.errors.paid')]);
        }
        DB::transaction(function () use ($delivery, $invoice, $wallets, $book) {
            if ($invoice) {
                $wallets->cancelInvoice($invoice, __('books.undo_note', ['title' => $book->title], 'ar'));
            }
            $this->audit->record('book.delivery_undone', $delivery, $delivery->only(['book_id', 'student_id', 'invoice_id']), []);
            $delivery->delete();
        });

        return response()->json(['message' => __('books.undone')]);
    }

    /** The term's classes within this user's reach (managers by track; teachers their own classes). */
    private function termLessons(Request $request, AcademicTerm $term): \Illuminate\Database\Eloquent\Builder
    {
        $user = $request->user();

        return \App\Models\Lesson::query()->tap(fn ($q) => TermScope::via($q, $term->id))->tap(fn ($q) => Track::scope($q, $user))
            ->when(! $user->can('lessons.manage') && ! $user->can('books.manage'), fn ($q) => $q->whereIn('id', TeacherScope::lessonIds($user)));
    }

    private function validated(Request $request, ?Book $book = null): array
    {
        $data = $request->validate([
            'academic_term_id' => [$book ? 'sometimes' : 'required', 'integer', 'exists:academic_terms,id'],
            'title' => [$book ? 'sometimes' : 'required', 'string', 'max:200'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'price_fils' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (array_key_exists('price_fils', $data) && $data['price_fils'] === null) {
            $data['price_fils'] = 0;
        }

        return $data;
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->can('books.view') || $request->user()->can('books.manage'), 403);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('books.manage'), 403);
    }

    private static function book(Book $b): array
    {
        return [
            'id' => $b->id, 'academic_term_id' => $b->academic_term_id, 'title' => $b->title,
            'subject' => $b->subject ? ['id' => $b->subject->id, 'name' => $b->subject->name()] : null,
            'level' => $b->level ? ['id' => $b->level->id, 'name' => $b->level->name()] : null,
            'price_fils' => $b->price_fils, 'stock' => $b->stock, 'is_active' => $b->is_active, 'notes' => $b->notes,
            'deliveries_count' => $b->deliveries_count ?? null,
        ];
    }

    private static function delivery(BookDelivery $d): array
    {
        $i = $d->invoice;

        return [
            'id' => $d->id, 'delivered_at' => $d->delivered_at?->toDateString(), 'delivered_by' => $d->deliverer?->name, 'notes' => $d->notes,
            'invoice' => $i ? ['id' => $i->id, 'invoice_no' => $i->invoice_no, 'amount_fils' => $i->amount_fils, 'paid_fils' => $i->paid_fils, 'status' => $i->status->value] : null,
        ];
    }
}
