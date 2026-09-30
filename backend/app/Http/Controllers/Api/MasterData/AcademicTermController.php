<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Master data
 * @subgroup Academic terms
 *
 * Terms feed the term selector of the staff shell (any staff member may list them); only terms.manage edits them.
 */
class AcademicTermController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('dashboard.view') || $user->can('terms.manage'), 403);

        $terms = AcademicTerm::query()
            ->when($user->can('terms.manage'), fn ($q) => $q->withCount(['packages', 'invoices']))
            ->ordered()->get();

        return response()->json([
            'data' => $terms->map(fn (AcademicTerm $t) => $this->present($t)),
            'current_id' => $terms->firstWhere('is_current', true)?->id,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('terms.manage'), 403);
        $data = $this->validated($request);
        $current = (bool) ($data['is_current'] ?? false);
        unset($data['is_current']);

        $term = AcademicTerm::create($data);
        // The first term becomes current, so the selector always has a default.
        if ($current || ! AcademicTerm::where('is_current', true)->exists()) {
            $term->makeCurrent();
        }
        $this->audit->record('term.created', $term, [], $term->only(['name_ar', 'academic_year', 'is_current']));

        return response()->json(['message' => __('terms.saved'), 'data' => $this->present($term->fresh()->loadCount(['packages', 'invoices']))], 201);
    }

    public function update(Request $request, AcademicTerm $academicTerm): JsonResponse
    {
        abort_unless($request->user()->can('terms.manage'), 403);
        $data = $this->validated($request, $academicTerm);
        $current = array_key_exists('is_current', $data) ? (bool) $data['is_current'] : null;
        unset($data['is_current']);
        // There is always one current term: it changes by making another term current, not by switching this one off.
        if ($current === false && $academicTerm->is_current) {
            throw ValidationException::withMessages(['is_current' => __('terms.errors.keep_current')]);
        }

        $old = $academicTerm->only(['name_ar', 'name_en', 'academic_year', 'start_date', 'end_date', 'is_current']);
        $academicTerm->update($data);
        if ($current === true) {
            $academicTerm->makeCurrent();
        }
        $this->audit->record('term.updated', $academicTerm, $old, $academicTerm->fresh()->only(array_keys($old)));

        return response()->json(['message' => __('terms.saved'), 'data' => $this->present($academicTerm->fresh()->loadCount(['packages', 'invoices']))]);
    }

    /** Make a term the current one (the selector's default for every staff member). */
    public function makeCurrent(Request $request, AcademicTerm $academicTerm): JsonResponse
    {
        abort_unless($request->user()->can('terms.manage'), 403);
        $academicTerm->makeCurrent();
        $this->audit->record('term.current', $academicTerm, [], ['is_current' => true]);

        return response()->json(['message' => __('terms.made_current'), 'data' => $this->present($academicTerm->fresh()->loadCount(['packages', 'invoices']))]);
    }

    public function destroy(Request $request, AcademicTerm $academicTerm): JsonResponse
    {
        abort_unless($request->user()->can('terms.manage'), 403);
        if ($academicTerm->is_current) {
            throw ValidationException::withMessages(['term' => __('terms.errors.delete_current')]);
        }
        if ($academicTerm->packages()->exists() || $academicTerm->invoices()->exists()) {
            throw ValidationException::withMessages(['term' => __('terms.errors.in_use')]);
        }
        $setup = fn (string $model) => $model::where('academic_term_id', $academicTerm->id)->exists();
        if ($setup(\App\Models\LevelSubject::class) || $setup(\App\Models\NightSupervisor::class) || $setup(\App\Models\TimetableSlot::class) || $setup(\App\Models\LevelRoom::class)) {
            throw ValidationException::withMessages(['term' => __('terms.errors.has_setup')]);
        }
        $this->audit->record('term.deleted', $academicTerm, $academicTerm->only(['name_ar', 'academic_year']), []);
        $academicTerm->delete();

        return response()->json(['message' => __('terms.deleted')]);
    }

    private function validated(Request $request, ?AcademicTerm $term = null): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:150', Rule::unique('academic_terms', 'name_ar')->ignore($term?->id)],
            'name_en' => ['required', 'string', 'max:150'],
            'academic_year' => ['nullable', 'string', 'regex:/^\d{4}\/\d{4}$/'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'is_current' => ['sometimes', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], ['academic_year.regex' => __('terms.errors.year_format'), 'end_date.after_or_equal' => __('terms.errors.range')]);
    }

    private function present(AcademicTerm $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name(),
            'name_ar' => $t->name_ar,
            'name_en' => $t->name_en,
            'academic_year' => $t->academic_year,
            'start_date' => $t->start_date?->toDateString(),
            'end_date' => $t->end_date?->toDateString(),
            'is_current' => $t->is_current,
            'legacy_label' => $t->legacy_label,
            'sort' => $t->sort,
            'packages_count' => $t->packages_count ?? null,
            'invoices_count' => $t->invoices_count ?? null,
        ];
    }
}
