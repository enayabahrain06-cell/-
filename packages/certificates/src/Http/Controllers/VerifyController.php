<?php

namespace Ahl\Certificates\Http\Controllers;

use Ahl\Certificates\Certificates;
use Ahl\Certificates\Contracts\Recipient;
use Ahl\Certificates\Enums\CertificateStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Certificates
 * @unauthenticated
 */
class VerifyController extends Controller
{
    /**
     * Verify a certificate from its QR code. Drafts are never verifiable; revoked certificates say so.
     * Shows only what is printed on the certificate itself.
     */
    public function __invoke(string $token): JsonResponse
    {
        $certificate = Certificates::model()::with('recipient')->where('verify_token', $token)
            ->where('status', '!=', CertificateStatus::Draft->value)->first();

        if (! $certificate) {
            return response()->json(['message' => __('certificates::certificates.errors.not_found')], 404);
        }
        $revoked = $certificate->isRevoked();
        $locale = app()->getLocale();
        $host = Certificates::host();
        $recipient = $certificate->recipient;

        return response()->json(['data' => [
            'valid' => ! $revoked,
            'status' => $certificate->status->value,
            'status_label' => __($revoked ? 'certificates::certificates.verify.revoked' : 'certificates::certificates.verify.valid'),
            'certificate_no' => $certificate->certificate_no,
            'type_label' => $certificate->typeLabel(),
            'title' => $certificate->title,
            'achievement' => $certificate->achievement,
            'grade_label' => $certificate->gradeLabel(),
            'recipient_name' => $recipient instanceof Recipient ? $recipient->certificateName() : null,
            'issued_on' => $certificate->issued_on?->toDateString(),
            'issued_on_secondary' => $certificate->issued_on ? $host->secondaryDate($certificate->issued_on, $locale) : null,
            'revoked_at' => $revoked ? $certificate->revoked_at?->toDateString() : null,
            'issuer' => $host->issuer(Certificates::locale($locale)),
        ]]);
    }
}
