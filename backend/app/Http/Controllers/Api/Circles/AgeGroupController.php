<?php

namespace App\Http\Controllers\Api\Circles;

use App\Enums\PackageGender;
use App\Http\Controllers\Controller;
use App\Models\AgeGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Lessons & locations
 * @subgroup Age groups
 *
 * Editable age bands used by circles, quick enrollment and reports. Managed by circle managers (lessons.manage).
 */
class AgeGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('lessons.view') || $request->user()->can('enrollment.quick'), 403);

        return response()->json(['data' => AgeGroup::withCount('lessons')->orderBy('sort')->orderBy('min_age')->get()->map(fn (AgeGroup $g) => $this->present($g))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('lessons.manage'), 403);
        $group = AgeGroup::create($this->validated($request));

        return response()->json(['message' => __('circles.age_group_saved'), 'data' => $this->present($group->loadCount('lessons'))], 201);
    }

    public function update(Request $request, AgeGroup $ageGroup): JsonResponse
    {
        abort_unless($request->user()->can('lessons.manage'), 403);
        $ageGroup->update($this->validated($request));

        return response()->json(['message' => __('circles.age_group_saved'), 'data' => $this->present($ageGroup->loadCount('lessons'))]);
    }

    public function destroy(Request $request, AgeGroup $ageGroup): JsonResponse
    {
        abort_unless($request->user()->can('lessons.manage'), 403);
        if ($ageGroup->lessons()->exists()) {
            throw ValidationException::withMessages(['age_group' => __('circles.errors.age_group_in_use')]);
        }
        $ageGroup->delete();

        return response()->json(['message' => __('circles.age_group_deleted')]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:100'],
            'name_en' => ['required', 'string', 'max:100'],
            'min_age' => ['required', 'integer', 'min:3', 'max:99'],
            'max_age' => ['nullable', 'integer', 'min:3', 'max:99', 'gte:min_age'],
            'track' => ['nullable', Rule::enum(PackageGender::class)],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ], ['max_age.gte' => __('circles.errors.range')]);
    }

    private function present(AgeGroup $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name(),
            'name_ar' => $g->name_ar,
            'name_en' => $g->name_en,
            'min_age' => $g->min_age,
            'max_age' => $g->max_age,
            'track' => $g->track?->value,
            'sort' => $g->sort,
            'is_active' => $g->is_active,
            'lessons_count' => $g->lessons_count ?? null,
        ];
    }
}
