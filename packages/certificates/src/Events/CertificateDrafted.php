<?php

namespace Ahl\Certificates\Events;

use Ahl\Certificates\Models\Certificate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/** A draft was created (manually, or by the host's automatic rules). */
class CertificateDrafted
{
    use Dispatchable;

    public function __construct(public Certificate $certificate, public ?Authenticatable $by = null) {}
}
