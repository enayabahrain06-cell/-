<?php

namespace App\Http\Controllers\Api\Portal;

use App\Enums\AttendanceStatus;
use App\Enums\EvaluationType;
use App\Enums\MessageStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentSummaryResource;
use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\LessonSession;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\User;
use App\Services\Evaluation\EvaluationService;
use App\Services\Progress\ProgressService;
use App\Services\Students\StudentProfileService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Student / guardian portal (work plan 3.4). Everything here is scoped to the signed-in user's own family:
 * a student sees only their own record, a guardian only the students whose guardian_user_id is them.
 * Staff-only fields stay hidden because the shared resources already check staff permissions.
 *
 * @group Student portal
 */
class FamilyPortalController extends Controller
{
    /**
     * Home cards: one per student of the family, with the circle and teacher, today's session and the student's
     * attendance in it, the next session, three KPIs, the latest teacher's note, this week's summary and the
     * amount due. Totals give the family's outstanding amount for the due-payment banner.
     */
    public function overview(Request $request, StudentProfileService $profiles, ProgressService $progress, WalletService $wallets): JsonResponse
    {
        $user = $request->user();
        $locale = app()->getLocale();
        $students = $this->family($user)->each(fn (Student $s) => $s->setRelation('wallet', $wallets->ensure($s)));

        $cards = $students->map(fn (Student $s) => $this->card($s, $profiles, $progress, $wallets, $locale))->values();

        return response()->json(['data' => [
            'role' => $user->student ? 'student' : 'guardian',
            'students' => $cards,
            'totals' => [
                'outstanding_fils' => $cards->sum('wallet.outstanding_fils'),
                'due_students' => $cards->where('wallet.outstanding_fils', '>', 0)->count(),
            ],
        ]]);
    }

    /**
     * Upcoming sessions (today and the next 13 days) of the family's circles, with the student's attendance when it
     * was already taken, plus each circle's weekly pattern. Optional student_id narrows to one family member.
     */
    public function schedule(Request $request): JsonResponse
    {
        $students = $this->scoped($request);
        $from = today();
        $to = today()->addDays(13);

        $rows = $students->flatMap(function (Student $s) use ($from, $to) {
            $lessonIds = $s->activeLessons->pluck('id');
            $sessions = LessonSession::with(['lesson:id,name,teacher_id,location_id', 'lesson.teacher:id,name', 'lesson.location:id,name', 'location:id,name'])
                ->whereIn('lesson_id', $lessonIds)
                ->whereBetween('session_date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('session_date')->orderBy('start_time')->get();
            $marks = Attendance::where('student_id', $s->id)->whereIn('lesson_session_id', $sessions->pluck('id'))->pluck('status', 'lesson_session_id');

            return $sessions->map(fn (LessonSession $x) => [
                'id' => $x->id,
                'student_id' => $s->id,
                'student_name' => $s->full_name,
                'date' => $x->session_date?->toDateString(),
                'start_time' => substr((string) $x->start_time, 0, 5),
                'end_time' => substr((string) $x->end_time, 0, 5),
                'lesson' => $x->lesson?->name,
                'teacher' => $x->lesson?->teacher?->name,
                'location' => ($x->location ?? $x->lesson?->location)?->name,
                'location_changed' => $x->location_id !== null && $x->location_id !== $x->lesson?->location_id,
                'status' => $x->status?->value,
                'attendance' => ($m = $marks->get($x->id)) instanceof AttendanceStatus ? $m->value : $m,
            ]);
        })->sortBy(fn ($r) => $r['date'].' '.$r['start_time'])->values();

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sessions' => $rows,
            'circles' => $students->flatMap(fn (Student $s) => $s->activeLessons->map(fn ($l) => [
                'student_id' => $s->id,
                'id' => $l->id,
                'name' => $l->name,
                'teacher' => $l->teacher?->name,
                'location' => $l->location?->name,
                'days' => $l->days,
                'start_time' => substr((string) $l->start_time, 0, 5),
                'end_time' => substr((string) $l->end_time, 0, 5),
            ]))->values(),
        ]]);
    }

    /**
     * WhatsApp messages the centre sent to this login (by user or by the login's phone), newest first. Only
     * messages that actually went out are listed; a message about a student outside the family never appears.
     */
    public function messages(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = $this->family($user)->pluck('id');

        $page = MessageLog::with('student:id,full_name')
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('recipient_phone', $user->phone))
            ->where(fn ($q) => $q->whereNull('student_id')->orWhereIn('student_id', $ids))
            ->whereIn('status', [MessageStatus::Sent->value, MessageStatus::Delivered->value, MessageStatus::Read->value])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn (MessageLog $m) => [
                'id' => $m->id,
                'type' => $m->type?->value,
                'body' => $m->body,
                'student' => $m->student ? ['id' => $m->student->id, 'full_name' => $m->student->full_name] : null,
                'sent_at' => display_tz($m->sent_at ?? $m->created_at)?->toIso8601String(),
                'status' => $m->status?->value,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * The active badge catalogue with what each family member has earned (count and last award), so the portal can
     * show earned and still-locked badges side by side. Optional student_id narrows to one family member.
     */
    public function badges(Request $request): JsonResponse
    {
        $students = $this->scoped($request);
        $locale = app()->getLocale();
        $catalog = \App\Models\Badge::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $awards = \App\Models\StudentBadge::whereIn('student_id', $students->pluck('id'))->get()->groupBy('student_id');

        return response()->json(['data' => $students->map(fn (Student $s) => [
            'student_id' => $s->id,
            'badges' => $catalog->map(function (\App\Models\Badge $b) use ($awards, $s, $locale) {
                $mine = ($awards->get($s->id) ?? collect())->where('badge_id', $b->id);

                return [
                    'id' => $b->id,
                    'key' => $b->key,
                    'name' => $b->localizedName($locale),
                    'description' => $locale === 'en' ? ($b->description_en ?: $b->description_ar) : ($b->description_ar ?: $b->description_en),
                    'icon' => $b->icon,
                    'earned' => $mine->isNotEmpty(),
                    'times' => $mine->count(),
                    'last_awarded_at' => $mine->max('awarded_at')?->toIso8601String(),
                ];
            })->values(),
        ])->values()]);
    }

    // ---------------------------------------------------------------------------------------------

    /** The signed-in student and the guardian's children, each once. */
    private function family(User $user): Collection
    {
        $with = ['wallet', 'activeLessons.teacher:id,name', 'activeLessons.location:id,name', 'activeLessons.package'];

        return Student::with($with)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('guardian_user_id', $user->id))
            ->orderBy('birth_date')->orderBy('id')->get();
    }

    /** The family, or the one member named by student_id (403 when that student is not in the family). */
    private function scoped(Request $request): Collection
    {
        $students = $this->family($request->user());
        if ($request->filled('student_id')) {
            $students = $students->where('id', $request->integer('student_id'))->values();
            abort_if($students->isEmpty(), 403);
        }

        return $students;
    }

    private function card(Student $s, StudentProfileService $profiles, ProgressService $progress, WalletService $wallets, string $locale): array
    {
        $lesson = $s->activeLessons->sortBy('pivot.joined_at')->first();
        $summary = $progress->summary($s, $locale);
        $currentJuz = $summary['position']['current']['juz'] ?? $summary['position']['next']['juz'] ?? null;
        $juzCell = $currentJuz ? collect($summary['juz_map'])->firstWhere('juz', $currentJuz) : null;

        $today = $lesson ? LessonSession::where('lesson_id', $lesson->id)->where('session_date', today()->toDateString())->orderBy('start_time')->first() : null;
        $next = $lesson ? LessonSession::with('location:id,name')->where('lesson_id', $lesson->id)
            ->where('session_date', '>', today()->toDateString())
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->orderBy('session_date')->orderBy('start_time')->first() : null;
        $todayMark = $today ? Attendance::where('lesson_session_id', $today->id)->where('student_id', $s->id)->first()?->status?->value : null;

        $recent = Evaluation::where('student_id', $s->id)->where('type', EvaluationType::Daily->value)
            ->where('evaluated_on', '>=', today()->subDays(30)->toDateString())->get();
        $latestNote = Evaluation::with('evaluator:id,name')->where('student_id', $s->id)->whereNotNull('note')->where('note', '!=', '')
            ->orderByDesc('evaluated_on')->orderByDesc('id')->first();

        $weekStart = today()->startOfWeek(\Carbon\Carbon::SATURDAY);
        $weekMarks = Attendance::where('student_id', $s->id)
            ->whereHas('session', fn ($q) => $q->where('session_date', '>=', $weekStart->toDateString())->where('session_date', '<=', today()->toDateString()))
            ->get(['status'])->countBy(fn ($a) => $a->status->value);
        $weekLedger = collect($summary['recent'])->filter(fn ($r) => $r['recorded_on'] >= $weekStart->toDateString())->take(3)->values();

        $outstanding = $wallets->outstandingFils($s);

        return [
            'student' => (new StudentSummaryResource($s))->resolve(request()),
            'circle' => $lesson ? [
                'id' => $lesson->id,
                'name' => $lesson->name,
                'teacher' => $lesson->teacher?->name,
                'location' => $lesson->location?->name,
                'days' => $lesson->days,
                'start_time' => substr((string) $lesson->start_time, 0, 5),
                'end_time' => substr((string) $lesson->end_time, 0, 5),
            ] : null,
            'today' => $today ? [
                'session_id' => $today->id,
                'date' => $today->session_date?->toDateString(),
                'start_time' => substr((string) $today->start_time, 0, 5),
                'end_time' => substr((string) $today->end_time, 0, 5),
                'status' => $today->status?->value,
                'attendance' => $todayMark,
            ] : null,
            'next_session' => $next ? [
                'date' => $next->session_date?->toDateString(),
                'start_time' => substr((string) $next->start_time, 0, 5),
                'end_time' => substr((string) $next->end_time, 0, 5),
                'location' => ($next->location ?? $lesson?->location)?->name,
            ] : null,
            'kpis' => [
                'attendance_percent' => $profiles->attendancePercent($s),
                'evaluation_average' => $recent->isEmpty() ? null : round((float) $recent->avg(fn (Evaluation $e) => $e->total()), 1),
                'evaluation_max' => 10 * count(EvaluationService::CRITERIA),
                'completed_juz' => $summary['completed_juz'],
                'memorized_ayahs' => $summary['memorized_ayahs'],
                'quran_percent' => $summary['quran_percent'],
            ],
            'progress' => [
                'position' => $summary['position'],
                'current_juz' => $juzCell,
                'plan' => $summary['plan'],
            ],
            'latest_note' => $latestNote ? [
                'note' => $latestNote->note,
                'date' => $latestNote->evaluated_on?->toDateString(),
                'teacher' => $latestNote->evaluator?->name,
            ] : null,
            'week' => [
                'from' => $weekStart->toDateString(),
                'attendance' => collect(AttendanceStatus::cases())->mapWithKeys(fn ($c) => [$c->value => (int) ($weekMarks[$c->value] ?? 0)])->all(),
                'recitations' => $weekLedger->all(),
            ],
            'wallet' => [
                'balance_fils' => $s->wallet?->balance_fils ?? 0,
                'outstanding_fils' => $outstanding,
                'is_due' => $outstanding > 0,
            ],
            'counts' => [
                'certificates' => $s->certificates()->where('status', \Ahl\Certificates\Enums\CertificateStatus::Approved->value)->count(),
                'badges' => \App\Models\StudentBadge::where('student_id', $s->id)->count(),
            ],
        ];
    }
}
