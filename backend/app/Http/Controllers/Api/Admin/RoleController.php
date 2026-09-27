<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * @group Users & permissions
 */
class RoleController extends Controller
{
    /** Permission matrix: every role with its permissions, plus all permissions grouped by module. */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::with('permissions')->orderBy('id')->get()->map(fn (Role $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'label' => __("enums.role.{$r->name}"),
            'permissions' => $r->permissions->pluck('name')->sort()->values(),
        ]);

        $permissions = Permission::orderBy('name')->get()->groupBy(fn (Permission $p) => explode('.', $p->name)[0])
            ->map(fn ($group) => $group->map(fn (Permission $p) => ['name' => $p->name, 'label' => __("permissions.{$p->name}")])->values());

        return response()->json(['roles' => $roles, 'permissions' => $permissions]);
    }

    /** Replace a role's permission set. Super Admin keeps all permissions. Audited. */
    public function updatePermissions(Request $request, Role $role, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $role);

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        if ($role->name === 'super_admin') {
            return response()->json(['message' => __('api.roles.super_admin_locked')], 422);
        }

        $old = $role->permissions->pluck('name')->sort()->values()->all();
        $role->syncPermissions($data['permissions']);
        $new = $role->fresh()->permissions->pluck('name')->sort()->values()->all();

        $audit->record('role.permissions_updated', $role, ['permissions' => $old], ['permissions' => $new]);

        return response()->json(['message' => __('api.saved'), 'role' => ['name' => $role->name, 'permissions' => $new]]);
    }
}
