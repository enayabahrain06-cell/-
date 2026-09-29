<?php

namespace App\Services\Certificates;

use Ahl\Certificates\CertificateService;
use Ahl\Certificates\Models\Certificate;
use App\Enums\AttemptStatus;
use App\Enums\CertificateGrade;
use App\Enums\CertificateSource;
use App\Enums\CertificateType;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Exam-pass certificate drafts for every graded, passed attempt without one yet.
 * Grade from the score: 90%+ excellent, 80%+ very good, otherwise good.
 */
class ExamCertificateIssuer
{
    public function __construct(private CertificateService $certificates) {}

    /** @return Collection<int, Certificate> */
    public function issue(Exam $exam, ?User $by = null): Collection
    {
        $existing = $exam->certificates()->active()->pluck('recipient_id')->all();

        return $exam->attempts()->where('status', AttemptStatus::Graded->value)->where('passed', true)
            ->whereNotIn('student_id', $existing)->with('student')->get()
            ->map(function ($attempt) use ($exam, $by) {
                $percent = $exam->total_marks > 0 ? $attempt->total_score * 100 / $exam->total_marks : 0;

                return $this->certificates->createDraft($attempt->student, CertificateType::Exam, [
                    'achievement' => $exam->name,
                    'grade' => match (true) {
                        $percent >= 90 => CertificateGrade::Excellent,
                        $percent >= 80 => CertificateGrade::VeryGood,
                        default => CertificateGrade::Good,
                    },
                    'context' => $exam->lesson,
                    'source' => CertificateSource::Exam,
                    'source_id' => $exam->id,
                    'details' => ['score' => $attempt->total_score, 'total' => $exam->total_marks],
                ], $by);
            });
    }
}
