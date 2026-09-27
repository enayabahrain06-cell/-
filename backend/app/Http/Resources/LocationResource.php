<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'map_link' => $this->map_link,
            'capacity' => $this->capacity,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'lessons_count' => $this->whenCounted('lessons'),
        ];
    }
}
