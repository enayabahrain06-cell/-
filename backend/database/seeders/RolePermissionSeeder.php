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
        'registrations.view', 'registrations.manage', 'enrollment.quick',
        'students.view', 'students.manage', 'students.photo',
        'teachers.view', 'teachers.manage',
        'locations.view', 'locations.manage',
        'lessons.view', 'lessons.manage',
        'attendance.view', 'attendance.record',
        'evaluations.view', 'evaluations.record',
        'exams.view', 'exams.manage', 'exams.grade',
        'certificates.view', 'certificates.issue', 'certificates.approve', 'certificates.templates',
        'lottery.view', 'lottery.manage',
        'honor.view', 'honor.manage', 'competitions.view', 'competitions.manage', 'competitions.judge', 'challenges.view', 'challenges.manage',
        'wallets.view', 'payments.record', 'wallets.adjust', 'refunds.manage',
        'messages.view', 'messages.manage', 'messages.send', 'whatsapp.status',
        'reports.view', 'reports.export',
        'settings.manage', 'audit.view',
        'terms.manage', 'levels.manage', 'subjects.manage',
        'term_setup.view', 'term_setup.manage',
        'menu.manage', // القائمة: menu order and hidden entries (Super Admin only)
        'nights.manage', // الليالي
        'distribution.manage', // توزيع المستويات، ترفيع الطلبة، تحديث المستوى
        'archive.view', 'archive.manage', // عرض الأرشيف / رفع الأرشيف
        'books.view', 'books.manage', // الكتب ومتابعة الكتب
    ];

    /** Default matrix (editable later from the admin panel). */
    public const MATRIX = [
        'super_admin' => '*',
        'supervisor' => [
            'dashboard.view',
            'packages.view', 'packages.manage',
            'registrations.view', 'registrations.manage', 'enrollment.quick',
            'students.view', 'students.manage', 'students.photo',
            'teachers.view',
            'locations.view', 'locations.manage',
            'lessons.view', 'lessons.manage',
            'attendance.view', 'attendance.record',
            'evaluations.view', 'evaluations.record',
            'exams.view', 'exams.manage', 'exams.grade',
            'certificates.view', 'certificates.issue', 'certificates.approve',
            'lottery.view', 'lottery.manage',
            'honor.view', 'honor.manage', 'competitions.view', 'competitions.manage', 'competitions.judge', 'challenges.view', 'challenges.manage',
            'wallets.view', 'payments.record', 'wallets.adjust', 'refunds.manage',
            'messages.view', 'messages.manage', 'messages.send', 'whatsapp.status',
            'reports.view', 'reports.export',
            'levels.manage', 'subjects.manage', // term setup master data; terms themselves stay with the Super Admin
            'term_setup.view', 'term_setup.manage', 'nights.manage',
            'distribution.manage', 'archive.view', 'archive.manage', 'books.view', 'books.manage',
        ],
        'teacher' => [
            'dashboard.view',
            'lessons.view', 'students.view',
            'enrollment.quick', // own circles only, no payments
            'attendance.view', 'attendance.record',
            'evaluations.view', 'evaluations.record',
            'exams.view', 'exams.grade',
            'certificates.view', 'certificates.issue', // drafts for own students; approval stays with supervisors
            'honor.view', 'competitions.view', 'competitions.judge', 'challenges.view', // judging only where assigned
            'messages.send',
            'term_setup.view', // the plan and timetable, read-only
            'books.view', // الكتب and متابعة الكتب of own classes, read-only
        ],
        'student' => [],
        'guardian' => [],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $added = [];
        foreach (self::PERMISSIONS as $name) {
            if (Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->wasRecentlyCreated) {
                $added[] = $name;
            }
        }

        foreach (self::MATRIX as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            // Only set the default matrix when the role is new, so admin edits survive re-seeding.
            if ($role->wasRecentlyCreated || $role->permissions()->count() === 0) {
                $role->syncPermissions($perms === '*' ? self::PERMISSIONS : $perms);
            } elseif ($perms !== '*' && ($new = array_intersect($added, $perms))) {
                // Permissions introduced by a later release reach existing roles by their default matrix.
                $role->givePermissionTo($new);
            }
        }

        // Super Admin always has everything, including permissions added in later releases.
        Role::findByName('super_admin', 'web')->syncPermissions(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
