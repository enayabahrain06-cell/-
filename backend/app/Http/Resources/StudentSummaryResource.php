<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact student card used in lists, rosters and the auth payload. */
class StudentSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_no' => $this->student_no,
            'full_name' => $this->full_name,
            'initial' => $this->initial(),
            'gender' => $this->gender?->value,
            'birth_date' => $this->birth_date?->toDateString(),
            'memorization_level' => $this->memorization_level?->value,
            'status' => $this->status?->value,
            'guardian_name' => $this->guardian_name,
            'guardian_phone' => $this->guardian_phone,
            'student_phone' => $this->student_phone,
            'locale' => $this->locale?->value,
            'has_photo' => $this->photo_path !== null,
            'photo_url' => $this->photoUrl('thumb'),
            'photo_urls' => $this->photoUrls(),
            'balance_fils' => $this->whenLoaded('wallet', fn () => $this->wallet?->balance_fils ?? 0),
            'is_due' => $this->whenLoaded('wallet', fn () => ($this->wallet?->balance_fils ?? 0) < 0),
        ];
    }
}
