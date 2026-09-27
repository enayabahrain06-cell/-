<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'collection' => $this->collection?->value ?? $this->collection,
            'mime' => $this->mime,
            'size' => $this->size,
            'original_name' => $this->original_name,
            'url' => route('media.show', ['media' => $this->id]),
            'created_at' => display_tz($this->created_at)?->toIso8601String(),
        ];
    }
}
