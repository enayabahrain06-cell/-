<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gender' => $this->gender?->value,
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => ['id' => $this->location->id, 'name' => $this->location->name]),
            'title' => $this->title,
            'source' => $this->source?->value,
            'booking_date' => $this->booking_date?->toDateString(),
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'exam_id' => $this->exam_id,
        ];
    }
}
