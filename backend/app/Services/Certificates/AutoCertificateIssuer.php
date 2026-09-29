<?php

namespace App\Services\Certificates;

use Ahl\Certificates\CertificateService;
use App\Enums\CertificateSource;
use App\Enums\CertificateType;
use App\Models\Certificate;
use App\Models\Student;
use App\Services\Progress\ProgressService;
use App\Support\Quran;

/**
 * Drafts a memorization-completion certificate the first time a student's ledger shows a whole juz
 * (source auto_juz, source_id = juz number) or the whole Quran (source_id = 0). A certificate that was
 * revoked is not drafted again. Switched off with certificates.auto_juz.
 */
class AutoCertificateIssuer
{
    public const WHOLE_QURAN = 0;

    public function __construct(private CertificateService $certificates, private ProgressService $progress) {}

    /** @return list<Certificate> the drafts created */
    public function afterProgress(Student $student): array
    {
        if (! setting('certificates.auto_juz', true)) {
            return [];
        }

        $set = $this->progress->memorizedSet($student);
        $done = collect($this->progress->juzMap($set, $this->progress->directionFor($student)))
            ->where('status', 'memorized')->pluck('juz')->all();
        if (! $done) {
            return [];
        }

        $existing = Certificate::forRecipient($student)->where('source', CertificateSource::AutoJuz->value)
            ->pluck('source_id')->map(fn ($v) => (int) $v)->all();
        $locale = $student->locale?->value ?? 'ar';
        $created = [];

        foreach ($done as $juz) {
            if (! in_array($juz, $existing, true)) {
                $created[] = $this->draft($student, $juz, __('certificates.auto_juz', ['juz' => $juz], $locale));
            }
        }
        if (count($set) >= Quran::TOTAL_AYAHS && ! in_array(self::WHOLE_QURAN, $existing, true)) {
            $created[] = $this->draft($student, self::WHOLE_QURAN, __('certificates.auto_quran', [], $locale));
        }

        return $created;
    }

    private function draft(Student $student, int $sourceId, string $achievement): Certificate
    {
        return $this->certificates->createDraft($student, CertificateType::Completion, [
            'achievement' => $achievement,
            'source' => CertificateSource::AutoJuz,
            'source_id' => $sourceId,
            'details' => ['juz' => $sourceId ?: null, 'whole_quran' => $sourceId === self::WHOLE_QURAN],
        ]);
    }
}
