<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Level;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Master data
 * @subgroup Levels
 *
 * Study levels above circles. Anyone who sees circles may list them (circle form and filters); levels.manage edits.
 */
class LevelController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('lessons.view') || $request->user()->can('levels.manage'), 403);

        $levels = Level::withCount('lessons')
            ->when($request->boolean('active'), fn ($q) => $q->where('is_active', true))
            ->ordered()->get();

        return response()->json(['data' => $levels->map(fn (Level $l) => $this->present($l))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('levels.manage'), 403);
        $level = Level::create($this->validated($request));
        $this->audit->record('level.created', $level, [], $level->only(['name_ar', 'code']));

        return response()->json(['message' => __('levels.saved'), 'data' => $this->present($level->loadCount('lessons'))], 201);
    }

    public function update(Request $request, Level $level): JsonResponse
    {
        abort_unless($request->user()->can('levels.manage'), 403);
        $old = $level->only(['name_ar', 'name_en', 'code', 'is_active']);
        $level->update($this->validated($request, $level));
        $this->audit->record('level.updated', $level, $old, $level->only(array_keys($old)));

        return response()->json(['message' => __('levels.saved'), 'data' => $this->present($level->loadCount('lessons'))]);
    }

    public function destroy(Request $request, Level $level): JsonResponse
    {
        abort_unless($request->user()->can('levels.manage'), 403);
        if ($level->lessons()->exists()) {
            throw ValidationException::withMessages(['level' => __('levels.errors.in_use')]);
        }
        $this->audit->record('level.deleted', $level, $level->only(['name_ar', 'code']), []);
        $level->delete();

        return response()->json(['message' => __('levels.deleted')]);
    }

    private function validated(Request $request, ?Level $level = null): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:100', Rule::unique('levels', 'name_ar')->ignore($level?->id)],
            'name_en' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:30', 'alpha_dash', Rule::unique('levels', 'code')->ignore($level?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ]);
    }

    private function present(Level $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name(),
            'name_ar' => $l->name_ar,
            'name_en' => $l->name_en,
            'code' => $l->code,
            'description' => $l->description,
            'sort' => $l->sort,
            'is_active' => $l->is_active,
            'lessons_count' => $l->lessons_count ?? null,
        ];
    }
}
