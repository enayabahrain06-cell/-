<?php

namespace App\Services\Reports;

use App\Enums\Role;
use App\Models\User;

/**
 * Every report the reports screen can open. `endpoint` is relative to /api; `filters` names the controls the screen
 * shows (period, lesson, package, teacher, gender, min_absences, months, issue_status). Titles and descriptions follow
 * the request locale.
 */
class ReportCatalog
{
    /** Reports served by ReportsController::show (the finance report keeps its own endpoint). */
    public const SERVED = ['attendance', 'absence', 'evaluation', 'exams', 'teachers', 'messages', 'juz', 'issues', 'high-issues', 'issue-trend', 'tracks'];

    private const ENTRIES = [
        ['key' => 'attendance', 'group' => 'attendance', 'icon' => 'attendance', 'filters' => ['period', 'lesson', 'package', 'teacher', 'gender'], 'chart' => 'attendance_by_day'],
        ['key' => 'absence', 'group' => 'attendance', 'icon' => 'alert', 'filters' => ['period', 'lesson', 'package', 'teacher', 'gender', 'min_absences']],
        ['key' => 'evaluation', 'group' => 'learning', 'icon' => 'evaluation', 'filters' => ['period', 'lesson', 'package', 'teacher', 'gender']],
        ['key' => 'juz', 'group' => 'learning', 'icon' => 'lessons', 'filters' => ['lesson', 'package', 'gender']],
        ['key' => 'issues', 'group' => 'learning', 'icon' => 'students', 'filters' => ['period', 'lesson', 'gender', 'issue_status']],
        ['key' => 'high-issues', 'group' => 'learning', 'icon' => 'alert', 'filters' => ['lesson', 'gender']],
        ['key' => 'issue-trend', 'group' => 'learning', 'icon' => 'chart', 'filters' => ['months', 'lesson', 'gender']],
        ['key' => 'exams', 'group' => 'exams', 'icon' => 'exams', 'filters' => ['period', 'lesson', 'package', 'gender']],
        ['key' => 'teachers', 'group' => 'staff', 'icon' => 'teachers', 'filters' => ['period', 'package', 'teacher', 'gender']],
        ['key' => 'messages', 'group' => 'communication', 'icon' => 'messages', 'filters' => ['period', 'gender']],
        ['key' => 'finance', 'group' => 'finance', 'icon' => 'payments', 'filters' => ['period', 'package', 'gender'], 'endpoint' => 'reports/finance'],
        ['key' => 'engagement', 'group' => 'overview', 'icon' => 'trophy', 'filters' => ['period', 'gender'], 'endpoint' => 'reports/engagement'],
        ['key' => 'tracks', 'group' => 'overview', 'icon' => 'reports', 'filters' => ['period']],
    ];

    public const GROUPS = ['attendance', 'learning', 'exams', 'staff', 'communication', 'finance', 'overview'];

    public static function allows(User $user, string $key): bool
    {
        return match ($key) {
            'finance' => $user->can('reports.view') || $user->can('wallets.view'),
            'messages' => $user->can('reports.view') && $user->can('messages.view'),
            'tracks' => $user->hasRole(Role::SuperAdmin->value),
            default => $user->can('reports.view'),
        };
    }

    /** @return list<array<string, mixed>> */
    public static function for(User $user): array
    {
        $superAdmin = $user->hasRole(Role::SuperAdmin->value);
        $out = [];
        foreach (self::ENTRIES as $e) {
            if (! self::allows($user, $e['key'])) {
                continue;
            }
            $filters = $e['filters'];
            // Gender is a filter only for someone who sees both tracks; teachers limited to their circles get no teacher filter.
            if (! $superAdmin && \App\Support\Track::genderFor($user) !== null) {
                $filters = array_values(array_diff($filters, ['gender']));
            }
            if (! $user->can('lessons.manage')) {
                $filters = array_values(array_diff($filters, ['teacher']));
            }
            $key = $e['key'];
            $out[] = [
                'key' => $key,
                'group' => $e['group'],
                'group_label' => __("reports.groups.{$e['group']}"),
                'icon' => $e['icon'],
                'title' => __('reports.catalog.'.str_replace('-', '_', $key).'.title'),
                'description' => __('reports.catalog.'.str_replace('-', '_', $key).'.description'),
                'endpoint' => $e['endpoint'] ?? "reports/{$key}",
                'filters' => $filters,
                'formats' => $user->can('reports.export') ? ['xlsx', 'pdf'] : [],
                'chart' => $e['chart'] ?? null,
            ];
        }

        return $out;
    }
}
