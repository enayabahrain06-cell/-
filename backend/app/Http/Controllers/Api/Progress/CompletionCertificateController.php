<?php

namespace App\Http\Controllers\Api\Progress;

use App\Enums\CertificateType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Models\Student;
use App\Services\Exams\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Memorization & evaluation
 */
class CompletionCertificateController extends Controller
{
    /**
     * Issue a completion certificate PDF (e.g. "Juz Amma", "Five ajza").
     * The PDF is stored as media (collection = certificate) and can be downloaded from the certificates endpoints.
     */
    public function store(Request $request, Student $student, CertificateService $certificates): JsonResponse
    {
        $this->authorize('issueCertificate', $student);

        $data = $request->validate([
            'achievement' => ['required', 'string', 'min:2', 'max:150'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
        ]);
        $locale = $student->locale?->value ?? 'ar';

        $certificate = Certificate::create([
            'certificate_no' => Certificate::nextNo(),
            'student_id' => $student->id,
            'type' => CertificateType::Completion,
            'lesson_id' => $data['lesson_id'] ?? null,
            'title' => __('progress.certificate_title', ['title' => $data['achievement']], $locale),
            'issued_on' => now()->toDateString(),
            'issued_by' => $request->user()->id,
        ]);
        $certificates->render($certificate, $student, ['exam' => $data['achievement'], 'verb' => 'completed']);

        return response()->json(['message' => __('progress.certificate_issued'), 'data' => new CertificateResource($certificate->fresh())], 201);
    }
}
