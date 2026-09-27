<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /** Full permission catalogue. Labels live in lang/{ar,en}/permissions.php. */
    public const PERMISSIONS = [
        'dashboard.view',
        'users.view', 'users.manage', 'roles.manage',
        'packages.view', 'packages.manage',
        'registrations.view', 'registrations.manage',
        'students.view', 'students.manage', 'students.photo',
        'teachers.view', 'teachers.manage',
        'locations.view', 'locations.manage',
        'lessons.view', 'lessons.manage',
        'attendance.view', 'attendance.record',
        'evaluations.view', 'evaluations.record',
        'exams.view', 'exams.manage', 'exams.grade',
        'lottery.view', 'lottery.manage',
        'wallets.view', 'payments.record', 'wallets.adjust', 'refunds.manage',
        'messages.view', 'messages.manage', 'messages.send', 'whatsapp.status',
        'reports.view', 'reports.export',
        'settings.manage', 'audit.view',
    ];

    /** Default matrix (editable later from the admin panel). */
    public const MATRIX = [
        'super_admin' => '*',
        'supervisor' => [
            'dashboard.view',
            'packages.view', 'packages.manage',
            'registrations.view', 'registrations.manage',
            'students.view', 'students.manage', 'students.photo',
            'teachers.view',
            'locations.view', 'locations.manage',
            'lessons.view', 'lessons.manage',
            'attendance.view', 'attendance.record',
            'evaluations.view', 'evaluations.record',
            'exams.view', 'exams.manage', 'exams.grade',
            'lottery.view', 'lottery.manage',
            'wallets.view', 'payments.record', 'wallets.adjust', 'refunds.manage',
            'messages.view', 'messages.manage', 'messages.send', 'whatsapp.status',
            'reports.view', 'reports.export',
        ],
        'teacher' => [
            'dashboard.view',
            'lessons.view', 'students.view',
            'attendance.view', 'attendance.record',
            'evaluations.view', 'evaluations.record',
            'exams.view', 'exams.grade',
            'messages.send',
        ],
        'student' => [],
        'guardian' => [],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::MATRIX as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            // Only set the default matrix when the role is new, so admin edits survive re-seeding.
            if ($role->wasRecentlyCreated || $role->permissions()->count() === 0) {
                $role->syncPermissions($perms === '*' ? self::PERMISSIONS : $perms);
            }
        }

        // Super Admin always has everything, including permissions added in later releases.
        Role::findByName('super_admin', 'web')->syncPermissions(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
