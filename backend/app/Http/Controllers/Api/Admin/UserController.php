<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * @group Users & permissions
 */
class UserController extends Controller
{
    /** List users with roles. Filters: role, search (name/phone), active. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::with(['roles', 'teacher'])
            ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('phone', 'like', $s));
            })
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 25));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request, AuditLogger $audit): UserResource
    {
        $this->authorize('create', User::class);

        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->except(['roles', 'teacher']));
            $user->syncRoles($request->validated('roles'));

            if (in_array('teacher', $request->validated('roles'), true)) {
                Teacher::updateOrCreate(['user_id' => $user->id], [
                    'gender' => $request->validated('teacher.gender') ?? $user->gender,
                    'specialization' => $request->validated('teacher.specialization'),
                    'is_active' => true,
                ]);
                // A teacher works in the track of their own gender unless a track is given explicitly.
                if (! $request->filled('track')) {
                    $user->update(['track' => $user->teacher()->toBase()->value('gender')]);
                }
            }

            return $user;
        });

        $audit->record('user.created', $user, [], ['roles' => $request->validated('roles')]);

        return new UserResource($user->load(['roles', 'teacher']));
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user->load(['roles', 'teacher', 'student', 'children']));
    }

    public function update(UpdateUserRequest $request, User $user, AuditLogger $audit): UserResource
    {
        $this->authorize('update', $user);

        $oldRoles = $user->roles->pluck('name')->sort()->values()->all();

        DB::transaction(function () use ($request, $user) {
            $data = $request->safe()->except(['roles', 'teacher', 'password']);
            if ($request->filled('password')) {
                $data['password'] = $request->validated('password');
            }
            $user->update($data);

            if ($request->has('roles')) {
                $user->syncRoles($request->validated('roles'));
            }

            if ($user->hasRole('teacher')) {
                Teacher::updateOrCreate(['user_id' => $user->id], array_filter([
                    'gender' => $request->validated('teacher.gender') ?? $user->teacher?->gender?->value ?? $user->gender,
                    'specialization' => $request->validated('teacher.specialization'),
                ], fn ($v) => $v !== null));
                if (! $request->filled('track')) {
                    $user->update(['track' => $user->teacher()->toBase()->value('gender')]);
                }
            }
        });

        $newRoles = $user->fresh()->roles->pluck('name')->sort()->values()->all();
        if ($oldRoles !== $newRoles) {
            $audit->record('user.roles_updated', $user, ['roles' => $oldRoles], ['roles' => $newRoles]);
        }

        return new UserResource($user->fresh()->load(['roles', 'teacher']));
    }

    /** Deactivate (soft) a user and revoke tokens. */
    public function destroy(User $user, AuditLogger $audit): JsonResponse
    {
        $this->authorize('delete', $user);

        $user->tokens()->delete();
        $user->update(['is_active' => false]);
        $audit->record('user.deactivated', $user);

        return response()->json(['message' => __('api.saved')]);
    }
}
