<?php

namespace App\Certificates;

use Ahl\Certificates\Contracts\CertificateNotifier;
use Ahl\Certificates\Models\Certificate;
use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Models\Student;
use App\Services\Messaging\MessageService;

/** WhatsApp congratulations with the verification link, to the guardian (and the student when they have their own number). */
class WhatsAppCertificateNotifier implements CertificateNotifier
{
    public function __construct(private MessageService $messages) {}

    public function enabled(): bool
    {
        return true;
    }

    public function send(Certificate $certificate, string $verifyUrl): int
    {
        $student = $certificate->recipient;
        if (! $student instanceof Student) {
            return 0;
        }
        $vars = [
            'title' => $certificate->title,
            'achievement' => (string) $certificate->achievement,
            'certificate_no' => $certificate->certificate_no,
            'link' => $verifyUrl,
        ];

        $sent = 0;
        foreach (array_unique(array_filter([$student->guardian_phone, $student->student_phone])) as $phone) {
            $log = $this->messages->send($phone, MessageType::CertificateIssued, $vars, $student->locale?->value ?? 'ar', $student, null,
                $phone === $student->guardian_phone ? RecipientType::Guardian : RecipientType::Student);
            $sent += $log ? 1 : 0;
        }

        return $sent;
    }
}
