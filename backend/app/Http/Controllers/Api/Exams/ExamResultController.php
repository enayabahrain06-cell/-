<?php

namespace App\Http\Controllers\Api\Exams;

use App\Enums\AttemptStatus;
use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Exports\ExamResultsExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Models\Exam;
use App\Services\Exams\CertificateService;
use App\Services\Exams\ExamService;
use App\Services\Messaging\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @group Exams
 * @subgroup Results and certificates
 */
class ExamResultController extends Controller
{
    public function __construct(private ExamService $exams) {}

    /** Score sheet, pass rate, average and top students. */
    public function show(Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);

        return response()->json($this->exams->results($exam) + ['exam' => ['id' => $exam->id, 'name' => $exam->name, 'total_marks' => $exam->total_marks, 'pass_mark' => $exam->pass_mark]]);
    }

    /** Excel export of the score sheet. */
    public function excel(Exam $exam)
    {
        $this->authorize('view', $exam);

        return Excel::download(new ExamResultsExport($exam, $this->exams->results($exam)), 'exam-'.$exam->id.'-results.xlsx');
    }

    /** Send each graded student's result to the guardian (and student phone). */
    public function send(Exam $exam, MessageService $messages): JsonResponse
    {
        $this->authorize('update', $exam);
        $sent = 0;

        $exam->attempts()->where('status', AttemptStatus::Graded->value)->with('student')->get()->each(function ($attempt) use ($exam, $messages, &$sent) {
            $s = $attempt->student;
            $locale = $s->locale?->value ?? 'ar';
            $vars = [
                'lesson' => $exam->name,
                'score' => $attempt->total_score.'/'.$exam->total_marks,
                'status' => __($attempt->passed ? 'exams.passed' : 'exams.failed', [], $locale),
            ];
            foreach (array_unique(array_filter([$s->guardian_phone, $s->student_phone])) as $phone) {
                $messages->send($phone, MessageType::ExamResult, $vars, $locale, $s, null, $phone === $s->guardian_phone ? RecipientType::Guardian : RecipientType::Student);
                $sent++;
            }
        });

        $exam->update(['results_sent_at' => now()]);

        return response()->json(['message' => __('exams.results_sent', ['count' => $sent]), 'sent' => $sent]);
    }

    /** Issue PDF certificates for passers who do not have one yet. */
    public function certificates(Request $request, Exam $exam, CertificateService $certificates): JsonResponse
    {
        $this->authorize('update', $exam);

        $issued = $certificates->issueForExam($exam, $request->user()->id);

        return response()->json([
            'issued' => $issued->count(),
            'data' => CertificateResource::collection($exam->certificates()->with('student')->get()),
        ]);
    }

    /** Stream a certificate PDF (staff, the student, or their guardian). */
    public function certificatePdf(Certificate $certificate, CertificateService $certificates): Response
    {
        $this->authorize('view', $certificate);

        $bytes = $certificates->pdfContents($certificate) ?? $certificates->render($certificate, $certificate->student);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$certificate->certificate_no.'.pdf"',
        ]);
    }
}
