<?php

namespace App\Http\Controllers\Api\TermSetup;

use App\Enums\WeekDay;
use App\Models\NightSupervisor;
use App\Models\User;
use App\Support\TermScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Term setup
 * @subgroup Night supervisors
 *
 * مشرفو الليالي: supervisors on duty per weekday in the selected term.
 */
class NightSupervisorController extends TermSetupBase
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $term = TermScope::single($request);
        $rows = NightSupervisor::with('user')->where('academic_term_id', $term->id)->orderBy('id')->get();
        $order = array_flip(WeekDay::values());

        return response()->json([
            'term' => self::term($term),
            'data' => $rows->sortBy(fn ($r) => $order[$r->weekday->value])->values()->map(fn ($r) => self::supervisor($r)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'weekday' => ['required', Rule::enum(WeekDay::class)],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        if (! User::whereKey($data['user_id'])->role('supervisor')->exists()) {
            throw ValidationException::withMessages(['user_id' => __('term_setup.errors.supervisor_role')]);
        }
        if (NightSupervisor::where(collect($data)->only(['academic_term_id', 'weekday', 'user_id'])->all())->exists()) {
            throw ValidationException::withMessages(['user_id' => __('term_setup.errors.duplicate_supervisor')]);
        }
        $row = NightSupervisor::create($data);

        return response()->json(['message' => __('term_setup.saved'), 'data' => self::supervisor($row->load('user'))], 201);
    }

    public function destroy(Request $request, NightSupervisor $nightSupervisor): JsonResponse
    {
        $this->authorizeManage($request);
        $nightSupervisor->delete();

        return response()->json(['message' => __('term_setup.deleted')]);
    }
}
