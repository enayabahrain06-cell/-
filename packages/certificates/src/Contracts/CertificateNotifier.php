<?php

namespace Ahl\Certificates\Contracts;

use Ahl\Certificates\Models\Certificate;

/** Tells the recipient about an approved certificate (WhatsApp, e-mail, SMS …). */
interface CertificateNotifier
{
    /** False hides the "send" action. */
    public function enabled(): bool;

    /** @return int how many messages went out */
    public function send(Certificate $certificate, string $verifyUrl): int;
}
