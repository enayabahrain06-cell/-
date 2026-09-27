<?php

namespace App\Services\Certificates;

use App\Enums\AttemptStatus;
use App\Enums\CertificateGrade;
use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\MediaCollection;
use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Exam;
use Illuminate\Support\Collection;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Media\MediaService;
use App\Services\Media\StudentPhotoService;
use App\Services\Messaging\MessageService;
use App\Services\Pdf\PdfService;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Certificates (spec section 16): drafts from any source, approval (renders the final PDF and
 * congratulates the family on WhatsApp), revocation, printing and public verification.
 */
class CertificateService
{
    public function __construct(
        private MediaService $media,
        private PdfService $pdf,
        private StudentPhotoService $photos,
        private MessageService $messages,
    ) {}

    /**
     * Create a draft certificate. Approved straight away when certificates.require_approval is off.
     *
     * @param  array{achievement?:string|null, grade?:string|null, title?:string|null, lesson_id?:int|null, exam_id?:int|null, honor_period_id?:int|null, competition_id?:int|null, source?:CertificateSource, source_id?:int|null, details?:array|null, issued_on?:string|null}  $data
     */
    public function createDraft(Student $student, CertificateType $type, array $data, ?User $by = null): Certificate
    {
        $template = CertificateTemplate::forType($type);
        $locale = $student->locale?->value ?? 'ar';

        $certificate = Certificate::create([
            'student_id' => $student->id,
            'type' => $type,
            'template_id' => $template->id,
            'title' => ($data['title'] ?? null) ?: $template->title($locale),
            'achievement' => $data['achievement'] ?? null,
            'grade' => $data['grade'] ?? null,
            'lesson_id' => $data['lesson_id'] ?? null,
            'exam_id' => $data['exam_id'] ?? null,
            'honor_period_id' => $data['honor_period_id'] ?? null,
            'competition_id' => $data['competition_id'] ?? null,
            'source' => $data['source'] ?? CertificateSource::Manual,
            'source_id' => $data['source_id'] ?? null,
            'details' => $data['details'] ?? null,
            'issued_on' => $data['issued_on'] ?? today()->toDateString(),
            'issued_by' => $by?->id,
            'status' => CertificateStatus::Draft,
        ]);

        if (! setting('certificates.require_approval', true)) {
            $this->approve($certificate, $by);
        }

        return $certificate;
    }

    /**
     * Draft exam-pass certificates for every graded, passed attempt without one yet.
     * Grade from the score: 90%+ excellent, 80%+ very good, otherwise good.
     *
     * @return Collection<int, Certificate>
     */
    public function issueForExam(Exam $exam, ?User $by = null): Collection
    {
        $existing = $exam->certificates()->active()->pluck('student_id')->all();

        return $exam->attempts()->where('status', AttemptStatus::Graded->value)->where('passed', true)
            ->whereNotIn('student_id', $existing)->with('student')->get()
            ->map(function ($attempt) use ($exam, $by) {
                $percent = $exam->total_marks > 0 ? $attempt->total_score * 100 / $exam->total_marks : 0;

                return $this->createDraft($attempt->student, CertificateType::Exam, [
                    'achievement' => $exam->name,
                    'grade' => match (true) {
                        $percent >= 90 => CertificateGrade::Excellent,
                        $percent >= 80 => CertificateGrade::VeryGood,
                        default => CertificateGrade::Good,
                    },
                    'exam_id' => $exam->id,
                    'lesson_id' => $exam->lesson_id,
                    'source' => CertificateSource::Exam,
                    'source_id' => $exam->id,
                    'details' => ['score' => $attempt->total_score, 'total' => $exam->total_marks],
                ], $by);
            });
    }

    /** True when the student already has a certificate from this source (e.g. the same juz), revoked ones included. */
    public function exists(Student $student, CertificateSource $source, int $sourceId): bool
    {
        return Certificate::where('student_id', $student->id)->where('source', $source->value)
            ->where('source_id', $sourceId)->exists();
    }

    public function update(Certificate $certificate, array $data): Certificate
    {
        $this->assertDraft($certificate);
        $certificate->fill(array_intersect_key($data, array_flip(['achievement', 'grade', 'lesson_id', 'issued_on'])));
        if (! empty($data['title'])) {
            $certificate->title = $data['title'];
        }
        $certificate->save();

        return $certificate;
    }

    public function delete(Certificate $certificate): void
    {
        $this->assertDraft($certificate);
        DB::transaction(function () use ($certificate) {
            $this->media->deleteCollection($certificate, MediaCollection::Certificate);
            $certificate->delete();
        });
    }

    /** Approve a draft: issue date = today, final PDF stored, family congratulated (certificates.notify_on_approve). */
    public function approve(Certificate $certificate, ?User $by = null): Certificate
    {
        $this->assertDraft($certificate);

        $certificate->forceFill([
            'status' => CertificateStatus::Approved,
            'approved_by' => $by?->id,
            'approved_at' => now(),
            'issued_on' => today()->toDateString(),
        ])->save();

        $this->render($certificate);
        app(AuditLogger::class)->record('certificate.approved', $certificate, [], ['certificate_no' => $certificate->certificate_no], $by?->id);

        if (setting('certificates.notify_on_approve', true)) {
            $this->send($certificate);
        }

        return $certificate;
    }

    public function revoke(Certificate $certificate, User $by, string $reason): Certificate
    {
        if (! $certificate->isApproved()) {
            throw ValidationException::withMessages(['status' => __('certificates.errors.not_approved')]);
        }

        DB::transaction(function () use ($certificate, $by, $reason) {
            $certificate->forceFill([
                'status' => CertificateStatus::Revoked,
                'revoked_by' => $by->id,
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ])->save();
            // The stored PDF would still look valid; later downloads are rendered fresh with a stamp.
            $this->media->deleteCollection($certificate, MediaCollection::Certificate);
        });
        app(AuditLogger::class)->record('certificate.revoked', $certificate, [], ['reason' => $reason], $by->id);

        return $certificate;
    }

    /** WhatsApp congratulations with the verification link, to the guardian (and the student when they have their own number). */
    public function send(Certificate $certificate): int
    {
        if (! $certificate->isApproved()) {
            throw ValidationException::withMessages(['status' => __('certificates.errors.not_approved')]);
        }
        $student = $certificate->student;
        $locale = $student->locale?->value ?? 'ar';
        $vars = [
            'title' => $certificate->title,
            'achievement' => (string) $certificate->achievement,
            'certificate_no' => $certificate->certificate_no,
            'link' => $this->verifyUrl($certificate),
        ];

        $sent = 0;
        foreach (array_unique(array_filter([$student->guardian_phone, $student->student_phone])) as $phone) {
            $log = $this->messages->send($phone, MessageType::CertificateIssued, $vars, $locale, $student, null,
                $phone === $student->guardian_phone ? RecipientType::Guardian : RecipientType::Student);
            $sent += $log ? 1 : 0;
        }
        if ($sent) {
            $certificate->forceFill(['sent_at' => now()])->save();
        }

        return $sent;
    }

    public function verifyUrl(Certificate $certificate): string
    {
        return config('ahl.frontend_url').'/verify/'.$certificate->verify_token;
    }

    /** Signed, short-lived download link (no login needed while it lasts). */
    public function downloadUrl(Certificate $certificate, bool $print = false, bool $view = false): string
    {
        return URL::temporarySignedRoute('certificates.download', now()->addMinutes((int) setting('certificates.link_minutes', 30)),
            ['certificate' => $certificate->id] + ($print ? ['print' => 1] : []) + ($view ? ['view' => 1] : []));
    }

    /** Stored PDF for approved certificates; drafts and revoked ones are rendered on demand with a stamp. */
    public function pdfContents(Certificate $certificate): string
    {
        if ($certificate->isApproved()) {
            $media = $certificate->mediaIn(MediaCollection::Certificate);
            if ($media && ($bytes = $this->media->contents($media))) {
                return $bytes;
            }
        }

        return $this->render($certificate);
    }

    /** Render the PDF. Approved certificates are stored as media (collection = certificate). */
    public function render(Certificate $certificate): string
    {
        $certificate->loadMissing('student', 'lesson', 'template');
        $template = $certificate->template ?? CertificateTemplate::forType($certificate->type);
        $student = $certificate->student;
        $locale = $student->locale?->value ?? 'ar';

        $bytes = $this->pdf->render('pdf.certificate', $this->viewData($template, $locale, [
            'name' => $student->full_name,
            'title' => $certificate->title ?: $template->title($locale),
            'body' => $this->fill($template->body($locale), $certificate, $locale),
            'grade' => $certificate->grade?->label($locale),
            'date' => $certificate->issued_on ?? today(),
            'certificate_no' => $certificate->certificate_no,
            'qr' => $certificate->isApproved() ? $this->qrDataUri($this->verifyUrl($certificate)) : null,
            'stamp' => match ($certificate->status) {
                CertificateStatus::Draft => __('certificates.draft_mark', [], $locale),
                CertificateStatus::Revoked => __('certificates.revoked_mark', [], $locale),
                default => null,
            },
            'photo' => $template->show_photo ? $this->photos->printableDataUri($student) : null,
        ]), 'landscape');

        if ($certificate->isApproved()) {
            $this->media->storeContents($certificate, MediaCollection::Certificate, $bytes, 'pdf', 'application/pdf', $certificate->certificate_no.'.pdf');
        }

        return $bytes;
    }

    /** A sample PDF of a template in one language, for the template editor. */
    public function preview(CertificateTemplate $template, string $locale): string
    {
        $sample = new Certificate(['achievement' => __('certificates.sample_achievement', [], $locale), 'grade' => CertificateGrade::Excellent, 'issued_on' => today()]);

        return $this->pdf->render('pdf.certificate', $this->viewData($template, $locale, [
            'name' => __('certificates.sample_name', [], $locale),
            'title' => $template->title($locale),
            'body' => $this->fill($template->body($locale), $sample, $locale),
            'grade' => CertificateGrade::Excellent->label($locale),
            'date' => today(),
            'certificate_no' => 'C'.now()->format('y').'00000',
            'qr' => $this->qrDataUri(config('ahl.frontend_url').'/verify/sample'),
            'stamp' => null,
            'photo' => null,
        ]), 'landscape');
    }

    public function qrDataUri(string $text): string
    {
        return 'data:image/png;base64,'.base64_encode((new Writer(new GDLibRenderer(220, 1)))->writeString($text));
    }

    /** Replace {achievement}, {grade}, {lesson}, {date} in template text. */
    public function fill(string $text, Certificate $certificate, string $locale): string
    {
        return trim(preg_replace('/\s{2,}/u', ' ', strtr($text, [
            '{achievement}' => (string) $certificate->achievement,
            '{grade}' => (string) $certificate->grade?->label($locale),
            '{lesson}' => (string) $certificate->lesson?->name,
            '{date}' => (string) $certificate->issued_on?->toDateString(),
        ])));
    }

    private function viewData(CertificateTemplate $template, string $locale, array $cert): array
    {
        $date = $cert['date'];

        return [
            'locale' => $locale,
            'ornament' => in_array($template->ornament_level, ['full', 'minimal', 'off'], true) ? $template->ornament_level : 'full',
            'authority' => setting($locale === 'en' ? 'authority.name_en' : 'authority.name_ar', config('ahl.authority.name_'.$locale)),
            'gregorian' => $date->format('Y/m/d'),
            'hijri' => setting('locale.show_hijri', true) ? (hijri_date($date, $locale === 'en' ? 'en' : 'ar') ?: null) : null,
            'signatures' => collect([1, 2])->map(fn ($slot) => [
                'name' => $template->{"signature{$slot}_name"},
                'title' => $template->{"signature{$slot}_title"},
                'image' => $this->signatureDataUri($template, $slot),
            ])->filter(fn ($s) => $s['name'] || $s['title'] || $s['image'])->values()->all(),
            'cert' => $cert,
        ];
    }

    private function signatureDataUri(CertificateTemplate $template, int $slot): ?string
    {
        $media = $template->signature($slot);
        $bytes = $media ? $this->media->contents($media) : null;

        return $bytes ? 'data:'.$media->mime.';base64,'.base64_encode($bytes) : null;
    }

    private function assertDraft(Certificate $certificate): void
    {
        if (! $certificate->isDraft()) {
            throw ValidationException::withMessages(['status' => __('certificates.errors.not_draft')]);
        }
    }
}
