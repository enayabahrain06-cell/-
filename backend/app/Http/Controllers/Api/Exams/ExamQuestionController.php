<?php

namespace App\Http\Controllers\Api\Exams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\QuestionRequest;
use App\Http\Resources\ExamQuestionResource;
use App\Models\Exam;
use App\Models\ExamQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Exams
 * @subgroup Question bank
 */
class ExamQuestionController extends Controller
{
    public function index(Exam $exam): AnonymousResourceCollection
    {
        $this->authorize('view', $exam);

        return ExamQuestionResource::collection($exam->questions()->get());
    }

    public function store(QuestionRequest $request, Exam $exam): ExamQuestionResource
    {
        $data = $request->validated();
        $data['sort_order'] ??= ((int) $exam->questions()->max('sort_order')) + 1;

        return new ExamQuestionResource($exam->questions()->create($data));
    }

    public function update(QuestionRequest $request, Exam $exam, ExamQuestion $question): ExamQuestionResource
    {
        abort_unless($question->exam_id === $exam->id, 404);
        $question->update($request->validated());

        return new ExamQuestionResource($question->fresh());
    }

    public function destroy(Exam $exam, ExamQuestion $question): JsonResponse
    {
        $this->authorize('update', $exam);
        abort_unless($question->exam_id === $exam->id, 404);
        $question->delete();

        return response()->json(['message' => __('api.deleted')]);
    }

    /** Reorder: {ids: [3,1,2]} */
    public function reorder(Request $request, Exam $exam): AnonymousResourceCollection
    {
        $this->authorize('update', $exam);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);

        foreach (array_values($data['ids']) as $i => $id) {
            $exam->questions()->whereKey($id)->update(['sort_order' => $i + 1]);
        }

        return ExamQuestionResource::collection($exam->questions()->get());
    }
}
