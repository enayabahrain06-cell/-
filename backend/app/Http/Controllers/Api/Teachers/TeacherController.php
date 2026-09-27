<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Teachers
 */
class TeacherController extends Controller
{
    /**
     * Teachers (users with the teacher role) with gender, specialization and circle load.
     * Filters: gender (male|female; "mixed" means female, the early-years rule), search, active.
     * Scoped staff only see teachers of their own track.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('teachers.view') || $request->user()->can('lessons.manage'), 403);

        $gender = $request->string('gender')->toString();
        if ($gender === 'mixed') {
            $gender = 'female';
        }
        $limit = Track::genderFor($request->user())?->value;

        $teachers = User::role('teacher')->with('teacher')
            ->withCount(['lessons as active_circles_count' => fn ($q) => $q->where('status', 'active')])
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->string('search').'%')->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->orderBy('name')
            ->get()
            ->filter(function (User $u) use ($gender, $limit) {
                $g = Track::staffGender($u)?->value;

                return (! $gender || $g === $gender) && (! $limit || $g === $limit);
            })
            ->values();

        return response()->json(['data' => $teachers->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'gender' => Track::staffGender($u)?->value,
            'specialization' => $u->teacher?->specialization,
            'is_active' => (bool) $u->is_active,
            'active_circles' => (int) $u->active_circles_count,
        ])]);
    }
}
