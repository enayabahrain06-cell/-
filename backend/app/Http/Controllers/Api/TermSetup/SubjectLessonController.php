<?php

namespace App\Http\Controllers\Api\TermSetup;

use App\Models\SubjectLesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Term setup
 * @subgroup Subject lessons
 *
 * دروس المواد: a subject's curriculum, per level or for every level. Not tied to a term.
 * Filters: subject_id, level_id (its own lessons plus the all-levels ones), active.
 */
class SubjectLessonController extends TermSetupBase
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $rows = SubjectLesson::with(['subject', 'level'])
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->when($request->filled('level_id'), fn ($q) => $q->forLevel($request->integer('level_id')))
            ->when($request->boolean('active'), fn ($q) => $q->where('is_active', true))
            ->ordered()->get();

        return response()->json(['data' => $rows->map(fn ($l) => self::subjectLesson($l))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $row = SubjectLesson::create($this->validated($request));

        return response()->json(['message' => __('term_setup.saved'), 'data' => self::subjectLesson($row->load(['subject', 'level']))], 201);
    }

    public function update(Request $request, SubjectLesson $subjectLesson): JsonResponse
    {
        $this->authorizeManage($request);
        $subjectLesson->update($this->validated($request, true));

        return response()->json(['message' => __('term_setup.saved'), 'data' => self::subjectLesson($subjectLesson->fresh()->load(['subject', 'level']))]);
    }

    /** Plan items that used the lesson keep their week and fall back to their own title (set on delete). */
    public function destroy(Request $request, SubjectLesson $subjectLesson): JsonResponse
    {
        $this->authorizeManage($request);
        \App\Models\PlanItem::where('subject_lesson_id', $subjectLesson->id)->whereNull('title')->update(['title' => $subjectLesson->title]);
        $subjectLesson->delete();

        return response()->json(['message' => __('term_setup.deleted')]);
    }

    private function validated(Request $request, bool $update = false): array
    {
        $data = $request->validate([
            'subject_id' => [$update ? 'sometimes' : 'required', 'integer', 'exists:subjects,id'],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'title' => [$update ? 'sometimes' : 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ]);
        if (array_key_exists('sort', $data) && $data['sort'] === null) {
            $data['sort'] = 0;
        }

        return $data;
    }
}
