<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $accepted = $this->accepted_count ?? $this->acceptedCount();

        return [
            'id' => $this->id,
            'name' => $this->localizedName(app()->getLocale()),
            'name_ar' => $this->name_ar ?: $this->name,
            'name_en' => $this->name_en ?: $this->name,
            'description' => $this->description,
            'min_age' => $this->min_age,
            'max_age' => $this->max_age,
            'gender' => $this->gender->value,
            'gender_label' => $this->gender->label(),
            'seats' => $this->seats,
            'seats_taken' => $accepted,
            'seats_left' => max(0, $this->seats - $accepted),
            'is_full' => $accepted >= $this->seats,
            'pending_count' => $this->whenNotNull($this->pending_count ?? null),
            'waitlist_count' => $this->whenNotNull($this->waitlist_count ?? null),
            'lessons_count' => $this->whenNotNull($this->lessons_count ?? null),
            'price_fils' => $this->price_fils,
            'days' => $this->days,
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'term' => $this->term,
            'plan_ayahs' => $this->plan_ayahs,
            'memorization_direction' => $this->memorization_direction?->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'suitability' => $this->when($this->getAttribute('suitability') !== null, fn () => $this->getAttribute('suitability')),
            // Public registration only: the package's open placement test, or null when there is none.
            'placement' => $this->when($this->getAttribute('placement') !== null, fn () => $this->getAttribute('placement') ?: null),
        ];
    }
}
