<?php

namespace Ahl\Certificates\Support;

use Ahl\Certificates\Contracts\CertificateNotifier;
use Ahl\Certificates\Models\Certificate;

/** Sends nothing; the "send" action is hidden. */
class NullNotifier implements CertificateNotifier
{
    public function enabled(): bool
    {
        return false;
    }

    public function send(Certificate $certificate, string $verifyUrl): int
    {
        return 0;
    }
}
