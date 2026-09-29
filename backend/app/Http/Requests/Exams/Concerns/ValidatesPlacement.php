<?php

namespace App\Http\Requests\Exams\Concerns;

use App\Enums\MemorizationLevel;
use Illuminate\Validation\Validator;

/**
 * Placement tests belong to a package (never a circle) and carry score bands: each band is a minimum
 * percentage and the memorization level it recommends. One band must start at 0 so every score lands somewhere.
 */
trait ValidatesPlacement
{
    protected function placementRules(): array
    {
        return [
            'level_bands' => ['nullable', 'array', 'max:'.count(MemorizationLevel::cases())],
            'level_bands.*.min' => ['required', 'integer', 'min:0', 'max:100', 'distinct'],
            'level_bands.*.level' => ['required', MemorizationLevel::rule(), 'distinct'],
        ];
    }

    protected function checkPlacement(Validator $v, bool $placement, mixed $packageId, mixed $lessonId, ?array $bands): void
    {
        if (! $placement) {
            return;
        }
        if (! $packageId) {
            $v->errors()->add('package_id', __('exams.placement.package_required'));
        }
        if ($lessonId) {
            $v->errors()->add('lesson_id', __('exams.placement.no_circle'));
        }
        if (! $bands) {
            $v->errors()->add('level_bands', __('exams.placement.bands_required'));
        } elseif (! collect($bands)->contains(fn ($b) => (int) ($b['min'] ?? -1) === 0)) {
            $v->errors()->add('level_bands', __('exams.placement.bands_from_zero'));
        }
    }

    /** Bands are stored highest minimum first, which is the order levelForPercent reads them. */
    protected function sortedBands(?array $bands): ?array
    {
        return $bands === null ? null : collect($bands)
            ->map(fn ($b) => ['min' => (int) $b['min'], 'level' => (string) $b['level']])
            ->sortByDesc('min')->values()->all();
    }
}
