<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->can('registrations.view') ?? false;

        return [
            'id' => $this->when($staff, $this->id),
            'request_no' => $this->request_no,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'waitlist_position' => $this->waitlist_position,
            'package' => $this->whenLoaded('package', fn () => [
                'id' => $this->package->id,
                'name' => $this->package->localizedName(app()->getLocale()),
                'start_date' => $this->package->start_date?->toDateString(),
                'price_fils' => $this->package->price_fils,
            ]),
            'full_name' => $this->full_name,
            'birth_date' => $this->when($staff, $this->birth_date?->toDateString()),
            'age_at_start' => $this->age_at_start,
            'gender' => $this->gender->value,
            'student_phone' => $this->when($staff, $this->student_phone),
            'guardian_name' => $this->when($staff, $this->guardian_name),
            'guardian_phone' => $this->when($staff, $this->guardian_phone),
            'memorization_level' => $this->memorization_level->value,
            'memorization_level_label' => $this->memorization_level->label(),
            'recommended_level' => $this->when($staff, $this->recommended_level?->value),
            'recommended_level_label' => $this->when($staff, $this->recommended_level?->label()),
            'final_level' => $this->when($staff, $this->final_level?->value),
            'final_level_label' => $this->when($staff, $this->final_level?->label()),
            'level_confirmed_by' => $this->when($staff, fn () => $this->levelConfirmer?->name),
            'level_confirmed_at' => $this->when($staff, fn () => display_tz($this->level_confirmed_at)?->toIso8601String()),
            'placement' => $this->when($staff && $this->placement_attempt_id !== null, fn () => app(\App\Services\Exams\PlacementService::class)->summary($this->placementAttempt)),
            'locale' => $this->locale->value,
            'has_photo' => $this->when($staff, fn () => $this->relationLoaded('media') ? $this->media->contains('collection', \App\Enums\MediaCollection::Photo) : $this->media()->where('collection', 'photo')->exists()),
            'reason' => $this->reason,
            'notes' => $this->when($staff, $this->notes),
            'decided_by' => $this->when($staff, fn () => $this->decider?->name),
            'decided_at' => display_tz($this->decided_at)?->toIso8601String(),
            'student' => $this->when($staff && $this->relationLoaded('student') && $this->student, fn () => new StudentSummaryResource($this->student)),
            'created_at' => display_tz($this->created_at)?->toIso8601String(),
        ];
    }
}
