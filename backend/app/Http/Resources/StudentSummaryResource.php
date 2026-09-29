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
            // National ID number: staff only, never shown to students or guardians.
            'cpr' => $this->when($request->user()?->can('students.view') ?? false, $this->cpr),
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
            // Cached position (recomputed from the memorization ledger on every change).
            'progress' => [
                'juz' => $this->progress_juz,
                'surah' => $this->progress_surah,
                'surah_name' => $this->progress_surah ? \App\Support\Quran::name($this->progress_surah, app()->getLocale()) : null,
                'ayah' => $this->progress_ayah,
                'memorized_ayahs' => (int) $this->memorized_ayahs,
            ],
            'circle' => $this->whenLoaded('activeLessons', fn () => ($l = $this->activeLessons->first()) ? ['id' => $l->id, 'name' => $l->name, 'teacher' => $l->relationLoaded('teacher') ? $l->teacher?->name : null] : null),
        ];
    }
}
