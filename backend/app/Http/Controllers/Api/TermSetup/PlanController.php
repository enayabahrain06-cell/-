<?php

namespace App\Http\Controllers\Api\TermSetup;

use App\Models\AcademicTerm;
use App\Models\LevelSubject;
use App\Models\PlanItem;
use App\Models\SubjectLesson;
use App\Support\TermScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Term setup
 * @subgroup Teaching plan
 *
 * الخطة / عرض الخطة: each level subject's curriculum lessons spread over the weeks of the term.
 */
class PlanController extends TermSetupBase
{
    /** Default length of a term without dates. */
    private const DEFAULT_WEEKS = 16;

    /** One level subject's plan items, by week. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $request->validate(['level_subject_id' => ['required', 'integer', 'exists:level_subjects,id']]);
        $ls = LevelSubject::with(['level', 'subject', 'teacher', 'academicTerm'])->findOrFail($request->integer('level_subject_id'));
        $items = PlanItem::with('subjectLesson')->where('level_subject_id', $ls->id)->orderBy('week_no')->orderBy('sort')->orderBy('id')->get();

        return response()->json([
            'level_subject' => self::levelSubject($ls),
            'weeks' => $this->weeks($ls->academicTerm, $items->max('week_no')),
            'data' => $items->map(fn ($p) => self::planItem($p)),
        ]);
    }

    /** عرض الخطة: a level's whole plan for the term — weeks as rows, its subjects as columns. */
    public function view(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $request->validate(['level_id' => ['required', 'integer', 'exists:levels,id']]);
        $term = TermScope::single($request);

        $subjects = LevelSubject::with(['level', 'subject', 'teacher', 'planItems' => fn ($q) => $q->with('subjectLesson')->orderBy('sort')->orderBy('id')])
            ->where('academic_term_id', $term->id)->where('level_id', $request->integer('level_id'))
            ->orderBy('sort')->orderBy('id')->get();
        $maxWeek = $subjects->flatMap->planItems->max('week_no');

        return response()->json([
            'term' => self::term($term),
            'weeks' => $this->weeks($term, $maxWeek),
            'subjects' => $subjects->map(fn (LevelSubject $ls) => self::levelSubject($ls) + [
                'items' => $ls->planItems->map(fn ($p) => self::planItem($p))->values(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $item = PlanItem::create($this->validated($request));

        return response()->json(['message' => __('term_setup.saved'), 'data' => self::planItem($item->load('subjectLesson'))], 201);
    }

    public function update(Request $request, PlanItem $planItem): JsonResponse
    {
        $this->authorizeManage($request);
        $planItem->update($this->validated($request, $planItem));

        return response()->json(['message' => __('term_setup.saved'), 'data' => self::planItem($planItem->fresh()->load('subjectLesson'))]);
    }

    public function destroy(Request $request, PlanItem $planItem): JsonResponse
    {
        $this->authorizeManage($request);
        $planItem->delete();

        return response()->json(['message' => __('term_setup.deleted')]);
    }

    private function validated(Request $request, ?PlanItem $item = null): array
    {
        $data = $request->validate([
            'level_subject_id' => [$item ? 'sometimes' : 'required', 'integer', 'exists:level_subjects,id'],
            'week_no' => [$item ? 'sometimes' : 'required', 'integer', 'min:1', 'max:52'],
            'subject_lesson_id' => ['nullable', 'integer', 'exists:subject_lessons,id'],
            'title' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            // U4: ayahs the week should add (Quran plans); drives "behind plan" on the dashboard.
            'target_ayahs' => ['nullable', 'integer', 'min:1', 'max:6236'],
        ]);
        $lessonId = array_key_exists('subject_lesson_id', $data) ? $data['subject_lesson_id'] : $item?->subject_lesson_id;
        $title = array_key_exists('title', $data) ? $data['title'] : $item?->title;
        if (! $lessonId && ! filled($title)) {
            throw ValidationException::withMessages(['title' => __('term_setup.errors.plan_title')]);
        }
        if ($lessonId) {
            $ls = LevelSubject::find($data['level_subject_id'] ?? $item?->level_subject_id);
            $ok = SubjectLesson::whereKey($lessonId)->where('subject_id', $ls?->subject_id)->forLevel($ls?->level_id)->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['subject_lesson_id' => __('term_setup.errors.lesson_subject')]);
            }
        }
        $data['sort'] ??= 0;

        return $data;
    }

    /** @return list<array{week_no:int, starts_on:?string}> Week n starts 7(n-1) days after the term start. */
    private function weeks(?AcademicTerm $term, ?int $maxUsed): array
    {
        $start = $term?->start_date ? Carbon::parse($term->start_date->toDateString()) : null;
        $count = $start && $term->end_date ? (int) ceil(($start->diffInDays(Carbon::parse($term->end_date->toDateString())) + 1) / 7) : self::DEFAULT_WEEKS;
        $count = max(1, min(52, max($count, (int) $maxUsed)));

        return array_map(fn ($n) => ['week_no' => $n, 'starts_on' => $start?->copy()->addDays(7 * ($n - 1))->toDateString()], range(1, $count));
    }
}
