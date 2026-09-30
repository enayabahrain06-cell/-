<?php

namespace App\Http\Controllers\Api\Grades;

use App\Enums\ExamType;
use App\Exports\SheetTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\GradeComponent;
use App\Models\Lesson;
use App\Models\LevelSubject;
use App\Models\Package;
use App\Models\SubjectLesson;
use App\Services\Exams\ExamService;
use App\Services\Grades\ExamGradeLinks;
use App\Services\Grades\ExamScoreImport;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @group Grades
 * @subgroup Exams
 *
 * U10 and الدروس المطلوبة: what an exam of a class or package can be linked to (grade components of kind exam, subject
 * lessons), and the exam's required lessons. رفع درجات الامتحان: the Excel template of a paper exam's students and the
 * preview of a filled one; the commit goes through PUT exams/{exam}/scores (exam_attempts stay the only place exam
 * scores live).
 */
class ExamGradesController extends Controller
{
    /** Link options for the exam form: grade components of kind exam and subject lessons of the class's level(s) this term. */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('exams.manage') || $user->can('grades.manage'), 403);
        $data = $request->validate(['lesson_id' => ['nullable', 'integer', 'exists:lessons,id'], 'package_id' => ['nullable', 'integer', 'exists:packages,id']]);

        [$termId, $levels] = [null, []];
        if (! empty($data['lesson_id'])) {
            $lesson = Lesson::with('package')->find($data['lesson_id']);
            $termId = $lesson->package?->academic_term_id;
            $levels = array_filter([$lesson->level_id]);
        } elseif (! empty($data['package_id'])) {
            $termId = Package::whereKey($data['package_id'])->value('academic_term_id');
            $levels = Lesson::where('package_id', $data['package_id'])->whereNotNull('level_id')->distinct()->pluck('level_id')->all();
        }
        if (! $termId || ! $levels) {
            return response()->json(['components' => [], 'lessons' => [], 'subjects' => [], 'in_term' => false]);
        }

        $components = GradeComponent::with(['levelSubject.level', 'levelSubject.subject'])->where('kind', 'exam')
            ->whereIn('level_subject_id', LevelSubject::where('academic_term_id', $termId)->whereIn('level_id', $levels)->select('id'))
            ->ordered()->get();

        $subjectIds = LevelSubject::where('academic_term_id', $termId)->whereIn('level_id', $levels)->pluck('subject_id')->push(\App\Models\Subject::quranId())->filter()->unique()->all();

        return response()->json([
            'in_term' => true,
            'subjects' => \App\Models\Subject::whereIn('id', $subjectIds)->ordered()->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name()])->values(),
            'components' => $components->map(fn (GradeComponent $c) => [
                'id' => $c->id, 'name' => $c->name(), 'weight' => (float) $c->weight, 'exam_id' => $c->exam_id,
                'subject' => ['id' => $c->levelSubject->subject->id, 'name' => $c->levelSubject->subject->name()],
                'level' => ['id' => $c->levelSubject->level->id, 'name' => $c->levelSubject->level->name()],
            ])->values(),
            'lessons' => SubjectLesson::with('level:id,name_ar,name_en')->where('is_active', true)
                ->where(fn ($w) => $w->whereNull('level_id')->orWhereIn('level_id', $levels))->ordered()->get()
                ->map(fn (SubjectLesson $l) => ExamGradeLinks::presentLesson($l) + ['subject_id' => $l->subject_id])->values(),
        ]);
    }

    /** الدروس المطلوبة of an exam: the chosen lessons and every lesson of its subject for its level(s) or all levels. */
    public function requiredLessons(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);
        abort_if($exam->type === ExamType::Placement, 404);
        $exam->load(['subject', 'lesson:id,name', 'package', 'requiredLessons']);

        return response()->json([
            'exam' => [
                'id' => $exam->id, 'name' => $exam->name, 'type' => $exam->type?->value, 'syllabus' => $exam->syllabus,
                'subject' => $exam->subject ? ['id' => $exam->subject->id, 'name' => $exam->subject->name()] : null,
                'where' => $exam->lesson?->name ?? $exam->package?->localizedName(app()->getLocale()),
            ],
            'selected' => $exam->requiredLessons->pluck('id')->map(fn ($v) => (int) $v)->values(),
            'available' => ExamGradeLinks::availableLessons($exam)->map(fn ($l) => ExamGradeLinks::presentLesson($l))->values(),
            'can_manage' => $this->canManageLessons($request, $exam),
        ]);
    }

    public function saveRequiredLessons(Request $request, Exam $exam, ExamGradeLinks $links): JsonResponse
    {
        abort_unless($this->canManageLessons($request, $exam), 403);
        abort_if($exam->type === ExamType::Placement, 404);
        $data = $request->validate(['ids' => ['present', 'array', 'max:300'], 'ids.*' => ['integer', 'distinct', 'exists:subject_lessons,id']]);
        DB::transaction(fn () => $links->syncRequired($exam, array_map('intval', $data['ids'])));

        return response()->json(['message' => __('grades.saved'), 'selected' => $exam->requiredLessons()->pluck('subject_lessons.id')->map(fn ($v) => (int) $v)->values()]);
    }

    /** Excel template: the paper exam's students (student_no, name) with an empty score column. */
    public function scoresTemplate(Exam $exam, ExamService $exams): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->authorize('grade', $exam);
        $this->assertPaperScores($exam);
        $attempts = $exam->attempts()->get()->keyBy('student_id');
        $rows = $exams->eligibleStudents($exam)->map(fn ($s) => [$s->student_no, $s->full_name, $attempts->get($s->id)?->total_score ?? ''])->all();

        return Excel::download(new SheetTemplateExport('scores', ExamScoreImport::COLUMNS, $rows,
            ['exam' => ['headings' => ['exam', 'total_marks', 'pass_mark'], 'rows' => [[$exam->name, $exam->total_marks, $exam->pass_mark]]]],
        ), 'exam-'.$exam->id.'-scores.xlsx');
    }

    /** Check a filled template without writing: unknown students, non-numbers, scores over the total, duplicates. */
    public function scoresPreview(Request $request, Exam $exam, ExamScoreImport $import): JsonResponse
    {
        $this->authorize('grade', $exam);
        $this->assertPaperScores($exam);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']]);
        $rows = $import->preview($exam, $request->file('file'));

        return response()->json([
            'rows' => $rows,
            'valid' => count(array_filter($rows, fn ($r) => $r['status'] === 'ok')),
            'invalid' => count(array_filter($rows, fn ($r) => $r['status'] === 'error')),
            'empty' => count(array_filter($rows, fn ($r) => $r['status'] === 'empty')),
        ]);
    }

    private function assertPaperScores(Exam $exam): void
    {
        if ($exam->type !== ExamType::Paper) {
            throw ValidationException::withMessages(['exam' => __('exams.not_paper')]);
        }
        if ($exam->questions()->exists()) {
            throw ValidationException::withMessages(['exam' => __('exams.paper_use_answers')]);
        }
    }

    private function canManageLessons(Request $request, Exam $exam): bool
    {
        $user = $request->user();

        return ($user->can('grades.manage') || $user->can('exams.manage')) && Track::allows($user, $exam->gender);
    }
}
