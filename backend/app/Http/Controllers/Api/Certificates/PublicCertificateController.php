<?php

namespace App\Http\Controllers\Api\Certificates;

use App\Enums\CertificateStatus;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use Illuminate\Http\JsonResponse;

/**
 * @group Certificates
 * @unauthenticated
 */
class PublicCertificateController extends Controller
{
    /**
     * Verify a certificate from its QR code. Drafts are never verifiable; revoked certificates say so.
     * Shows only what is printed on the certificate itself.
     */
    public function verify(string $token): JsonResponse
    {
        $certificate = Certificate::with('student:id,full_name')->where('verify_token', $token)
            ->where('status', '!=', CertificateStatus::Draft->value)->first();

        if (! $certificate) {
            return response()->json(['message' => __('certificates.errors.not_found')], 404);
        }
        $revoked = $certificate->status === CertificateStatus::Revoked;
        $locale = app()->getLocale();

        return response()->json(['data' => [
            'valid' => ! $revoked,
            'status' => $certificate->status->value,
            'status_label' => __($revoked ? 'certificates.verify.revoked' : 'certificates.verify.valid'),
            'certificate_no' => $certificate->certificate_no,
            'type_label' => $certificate->type->label(),
            'title' => $certificate->title,
            'achievement' => $certificate->achievement,
            'grade_label' => $certificate->grade?->label(),
            'student_name' => $certificate->student?->full_name,
            'issued_on' => $certificate->issued_on?->toDateString(),
            'issued_on_hijri' => $certificate->issued_on ? (hijri_date($certificate->issued_on, $locale === 'en' ? 'en' : 'ar') ?: null) : null,
            'revoked_at' => $revoked ? $certificate->revoked_at?->toDateString() : null,
            'authority' => setting($locale === 'en' ? 'authority.name_en' : 'authority.name_ar', config('ahl.authority.name_'.$locale)),
        ]]);
    }
}
