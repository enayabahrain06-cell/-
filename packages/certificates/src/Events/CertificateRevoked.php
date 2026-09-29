<?php

namespace Ahl\Certificates\Events;

use Ahl\Certificates\Models\Certificate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/** An approved certificate was revoked. */
class CertificateRevoked
{
    use Dispatchable;

    public function __construct(public Certificate $certificate, public ?Authenticatable $by = null) {}
}
