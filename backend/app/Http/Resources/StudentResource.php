<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Full student profile header. */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = (new StudentSummaryResource($this->resource))->toArray($request);

        return $summary + [
            'age' => $this->birth_date ? (int) $this->birth_date->diffInYears(now(), true) : null,
            'memorization_level_label' => $this->memorization_level?->label(),
            'status_label' => $this->status?->label(),
            'yearly_target_ayahs' => $this->yearly_target_ayahs,
            // Home address (from the ID card or typed by staff): staff only, like the CPR.
            'address' => $this->when($request->user()?->can('students.view') ?? false, $this->address),
            'notes' => $this->when($request->user()?->can('students.manage') ?? false, $this->notes),
            'guardian' => $this->whenLoaded('guardian', fn () => $this->guardian ? ['id' => $this->guardian->id, 'name' => $this->guardian->name, 'phone' => $this->guardian->phone, 'locale' => $this->guardian->locale?->value] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'phone' => $this->user->phone, 'last_login_at' => display_tz($this->user->last_login_at)?->toIso8601String()] : null),
            'lessons' => $this->whenLoaded('lessons', fn () => $this->lessons->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'status' => $l->pivot->status,
                'joined_at' => $l->pivot->joined_at,
                'current_memorization' => $l->pivot->current_memorization,
                'current_revision' => $l->pivot->current_revision,
                'teacher' => $l->teacher?->name,
                'package' => $l->package?->localizedName(app()->getLocale()),
                'location' => $l->location?->name,
                'days' => $l->days,
                'start_time' => substr((string) $l->start_time, 0, 5),
                'end_time' => substr((string) $l->end_time, 0, 5),
            ])),
            'created_at' => display_tz($this->created_at)?->toIso8601String(),
        ];
    }
}
