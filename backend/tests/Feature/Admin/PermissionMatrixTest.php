<?php

use App\Models\AuditLog;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('lets the super admin read and edit the permission matrix, with an audit row', function () {
    actingAsRole('super_admin');

    $this->getJson('/api/admin/roles')->assertOk()->assertJsonStructure(['roles', 'permissions']);

    $role = Role::findByName('teacher', 'web');
    $this->putJson("/api/admin/roles/{$role->id}/permissions", ['permissions' => ['dashboard.view', 'attendance.record']])
        ->assertOk()
        ->assertJsonPath('role.permissions', ['attendance.record', 'dashboard.view']);

    expect(Role::findByName('teacher', 'web')->permissions->pluck('name')->sort()->values()->all())->toBe(['attendance.record', 'dashboard.view']);

    $audit = AuditLog::where('action', 'role.permissions_updated')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->new_values['permissions'])->toBe(['attendance.record', 'dashboard.view'])
        ->and($audit->old_values['permissions'])->toContain('evaluations.record');
});

it('refuses to edit super admin permissions', function () {
    actingAsRole('super_admin');
    $role = Role::findByName('super_admin', 'web');

    $this->putJson("/api/admin/roles/{$role->id}/permissions", ['permissions' => []])->assertStatus(422);
});

it('forbids supervisors and teachers from the matrix and user management', function () {
    actingAsRole('supervisor');
    $role = Role::findByName('teacher', 'web');
    $this->putJson("/api/admin/roles/{$role->id}/permissions", ['permissions' => []])->assertForbidden();
    $this->getJson('/api/admin/users')->assertForbidden();

    actingAsRole('teacher');
    $this->getJson('/api/admin/roles')->assertForbidden();
    $this->postJson('/api/admin/users', [])->assertForbidden();
});

it('creates staff users with roles and a teacher profile', function () {
    actingAsRole('super_admin');

    $this->postJson('/api/admin/users', [
        'name' => 'الشيخ خالد',
        'phone' => '36000030',
        'password' => 'secret123',
        'gender' => 'male',
        'roles' => ['teacher'],
        'teacher' => ['specialization' => 'حفص'],
    ])->assertCreated()
        ->assertJsonPath('data.phone', '+97336000030')
        ->assertJsonPath('data.roles.0', 'teacher')
        ->assertJsonPath('data.teacher.specialization', 'حفص');

    $this->postJson('/api/admin/users', ['name' => 'x', 'phone' => '36000030', 'password' => 'secret123', 'roles' => ['teacher']])
        ->assertStatus(422)->assertJsonValidationErrors('phone');
});

it('requires authentication and blocks inactive tokens', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();

    $user = User::factory()->inactive()->create();
    $user->assignRole('teacher');
    $this->actingAs($user, 'sanctum')->getJson('/api/auth/me')->assertForbidden();
});

it('propagates a guardian phone change to all children', function () {
    $guardian = User::factory()->withoutPassword()->create(['phone' => '+97336000040']);
    $guardian->assignRole('guardian');
    $s1 = \App\Models\Student::factory()->create(['guardian_user_id' => $guardian->id, 'guardian_phone' => $guardian->phone]);
    $s2 = \App\Models\Student::factory()->create(['guardian_user_id' => $guardian->id, 'guardian_phone' => $guardian->phone]);

    actingAsRole('super_admin');
    $this->putJson("/api/admin/users/{$guardian->id}", ['phone' => '36000041'])->assertOk();

    expect($s1->fresh()->guardian_phone)->toBe('+97336000041')
        ->and($s2->fresh()->guardian_phone)->toBe('+97336000041');
});
