<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'gender' => $this->gender,
            'track' => $this->track?->value ?? 'both',
            'locale' => $this->locale?->value ?? $this->locale,
            'is_active' => $this->is_active,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'permissions' => $this->when($this->relationLoaded('roles'), fn () => $this->getAllPermissions()->pluck('name')->values()),
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher ? [
                'id' => $this->teacher->id,
                'gender' => $this->teacher->gender?->value,
                'specialization' => $this->teacher->specialization,
            ] : null),
            'student' => $this->whenLoaded('student', fn () => $this->student ? new StudentSummaryResource($this->student) : null),
            'children' => $this->whenLoaded('children', fn () => StudentSummaryResource::collection($this->children)),
            'last_login_at' => display_tz($this->last_login_at)?->toIso8601String(),
            'created_at' => display_tz($this->created_at)?->toIso8601String(),
        ];
    }
}
