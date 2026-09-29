<?php

namespace App\Http\Controllers\Api\Progress;

use Ahl\Certificates\CertificateService;
use Ahl\Certificates\Http\Resources\CertificateResource;
use App\Enums\CertificateGrade;
use App\Enums\CertificateType;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @group Memorization & evaluation
 */
class CompletionCertificateController extends Controller
{
    /**
     * Draft a memorization-completion certificate (e.g. "Juz Amma", "Five ajza").
     * It reaches the family once approved on the Certificates page.
     */
    public function store(Request $request, Student $student, CertificateService $certificates): JsonResponse
    {
        $this->authorize('issueCertificate', $student);

        $data = $request->validate([
            'achievement' => ['required', 'string', 'min:2', 'max:150'],
            'grade' => ['nullable', Rule::enum(CertificateGrade::class)],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
        ]);

        $certificate = $certificates->createDraft($student, CertificateType::Completion, [
            'achievement' => $data['achievement'],
            'grade' => $data['grade'] ?? null,
            'context' => isset($data['lesson_id']) ? Lesson::find($data['lesson_id']) : null,
            'title' => __('progress.certificate_title', ['title' => $data['achievement']], $student->locale?->value ?? 'ar'),
        ], $request->user());

        return response()->json(['message' => __('progress.certificate_issued'), 'data' => new CertificateResource($certificate->fresh(['recipient', 'context']))], 201);
    }
}
