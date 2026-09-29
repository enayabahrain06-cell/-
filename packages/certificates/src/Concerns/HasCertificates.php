<?php

namespace Ahl\Certificates\Concerns;

use Ahl\Certificates\Certificates;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** For recipient models: $model->certificates(). */
trait HasCertificates
{
    public function certificates(): MorphMany
    {
        return $this->morphMany(Certificates::model(), 'recipient');
    }
}
