<?php

namespace App\Certificates;

use Ahl\Certificates\Events\CertificateApproved;
use Ahl\Certificates\Events\CertificateRevoked;
use App\Services\AuditLogger;

/** Approvals and revocations go to the audit log. */
class AuditCertificateEvents
{
    public function __construct(private AuditLogger $audit) {}

    public function approved(CertificateApproved $e): void
    {
        $this->audit->record('certificate.approved', $e->certificate, [], ['certificate_no' => $e->certificate->certificate_no], $e->by?->getAuthIdentifier());
    }

    public function revoked(CertificateRevoked $e): void
    {
        $this->audit->record('certificate.revoked', $e->certificate, [], ['reason' => $e->certificate->revoke_reason], $e->by?->getAuthIdentifier());
    }
}
