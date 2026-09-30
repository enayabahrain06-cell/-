<?php

namespace App\Http\Controllers\Api\TermSetup;

use App\Models\LevelSubject;
use App\Models\User;
use App\Support\TermScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Term setup
 * @subgroup Level subjects
 *
 * مواد المستويات: the subjects each level studies in the selected term, with a default teacher.
 */
class LevelSubjectController extends TermSetupBase
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $term = TermScope::single($request);

        $rows = LevelSubject::with(['level', 'subject', 'teacher'])->withCount('planItems')
            ->where('academic_term_id', $term->id)
            ->when($request->filled('level_id'), fn ($q) => $q->where('level_id', $request->integer('level_id')))
            ->join('levels', 'levels.id', '=', 'level_subjects.level_id')
            ->orderBy('levels.sort')->orderBy('levels.id')->orderBy('level_subjects.sort')->orderBy('level_subjects.id')
            ->select('level_subjects.*')->get();

        return response()->json(['term' => self::term($term), 'data' => $rows->map(fn ($ls) => self::levelSubject($ls))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $this->validated($request);
        $row = LevelSubject::create($data);

        return response()->json(['message' => __('term_setup.saved'), 'data' => self::levelSubject($row->load(['level', 'subject', 'teacher'])->loadCount('planItems'))], 201);
    }

    public function update(Request $request, LevelSubject $levelSubject): JsonResponse
    {
        $this->authorizeManage($request);
        $levelSubject->update($this->validated($request, $levelSubject));

        return response()->json(['message' => __('term_setup.saved'), 'data' => self::levelSubject($levelSubject->fresh()->load(['level', 'subject', 'teacher'])->loadCount('planItems'))]);
    }

    /** Removes the subject from the level for this term, with its plan items. */
    public function destroy(Request $request, LevelSubject $levelSubject): JsonResponse
    {
        $this->authorizeManage($request);
        $levelSubject->delete();

        return response()->json(['message' => __('term_setup.deleted')]);
    }

    private function validated(Request $request, ?LevelSubject $row = null): array
    {
        $data = $request->validate([
            'academic_term_id' => [$row ? 'sometimes' : 'required', 'integer', 'exists:academic_terms,id'],
            'level_id' => [$row ? 'sometimes' : 'required', 'integer', 'exists:levels,id'],
            'subject_id' => [$row ? 'sometimes' : 'required', 'integer', 'exists:subjects,id'],
            'teacher_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'weekly_sessions' => ['nullable', 'integer', 'min:1', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
        $key = [
            'academic_term_id' => $data['academic_term_id'] ?? $row?->academic_term_id,
            'level_id' => $data['level_id'] ?? $row?->level_id,
            'subject_id' => $data['subject_id'] ?? $row?->subject_id,
        ];
        if (LevelSubject::where($key)->when($row, fn ($q) => $q->whereKeyNot($row->id))->exists()) {
            throw ValidationException::withMessages(['subject_id' => __('term_setup.errors.duplicate_subject')]);
        }
        if (! empty($data['teacher_id']) && ! User::whereKey($data['teacher_id'])->role('teacher')->exists()) {
            throw ValidationException::withMessages(['teacher_id' => __('term_setup.errors.teacher_role')]);
        }
        $data['sort'] ??= 0;

        return $data;
    }
}
