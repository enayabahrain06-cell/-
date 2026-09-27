<?php

namespace App\Services\Exams;

use App\Enums\AttemptStatus;
use App\Enums\CertificateType;
use App\Enums\MediaCollection;
use App\Models\Certificate;
use App\Models\Exam;
use App\Models\Student;
use App\Services\Media\MediaService;
use App\Services\Pdf\PdfService;
use Illuminate\Support\Collection;

class CertificateService
{
    public function __construct(private MediaService $media, private PdfService $pdf) {}

    /** Issue exam-pass certificates for every graded, passed attempt that has none yet. */
    public function issueForExam(Exam $exam, ?int $issuedBy = null): Collection
    {
        $existing = $exam->certificates()->pluck('student_id')->all();
        $issued = new Collection;

        $exam->attempts()->where('status', AttemptStatus::Graded->value)->where('passed', true)
            ->whereNotIn('student_id', $existing)->with('student')->get()
            ->each(function ($attempt) use ($exam, $issuedBy, $issued) {
                $cert = Certificate::create([
                    'certificate_no' => Certificate::nextNo(),
                    'student_id' => $attempt->student_id,
                    'type' => CertificateType::Exam,
                    'exam_id' => $exam->id,
                    'lesson_id' => $exam->lesson_id,
                    'title' => __('exams.certificate_title', ['exam' => $exam->name], $attempt->student->locale?->value ?? 'ar'),
                    'issued_on' => now()->toDateString(),
                    'issued_by' => $issuedBy,
                ]);
                $this->render($cert, $attempt->student, ['score' => $attempt->total_score, 'total' => $exam->total_marks, 'exam' => $exam->name]);
                $issued->push($cert);
            });

        return $issued;
    }

    /** Render the PDF and store it as media (collection = certificate). Returns the raw PDF. */
    public function render(Certificate $certificate, Student $student, array $extra = []): string
    {
        $locale = $student->locale?->value ?? 'ar';
        $issued = $certificate->issued_on;

        $bytes = $this->pdf->render('pdf.certificate', [
            'locale' => $locale,
            'certificate' => $certificate,
            'student' => $student,
            'authority' => setting($locale === 'en' ? 'authority.name_en' : 'authority.name_ar', config('ahl.authority.name_'.$locale)),
            'gregorian' => $issued->format('Y/m/d'),
            'hijri' => hijri_date($issued, $locale === 'en' ? 'en' : 'ar') ?: null,
            'extra' => $extra,
        ], 'landscape');
        $this->media->storeContents($certificate, MediaCollection::Certificate, $bytes, 'pdf', 'application/pdf', $certificate->certificate_no.'.pdf');

        return $bytes;
    }

    public function pdfContents(Certificate $certificate): ?string
    {
        $media = $certificate->mediaIn(MediaCollection::Certificate);

        return $media ? $this->media->contents($media) : null;
    }
}
