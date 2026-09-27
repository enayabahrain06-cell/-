<?php

namespace App\Models\Concerns;

use App\Enums\MediaCollection;
use App\Models\Media;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasMedia
{
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }

    public function mediaIn(MediaCollection $collection): ?Media
    {
        return $this->media->firstWhere('collection', $collection->value)
            ?? $this->media()->where('collection', $collection->value)->latest('id')->first();
    }
}
