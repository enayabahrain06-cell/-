<?php

namespace App\Console\Commands;

use App\Services\Lessons\SessionSync;
use Illuminate\Console\Command;

/**
 * sessions:sync — bring every class's sessions in line with الجدول الدراسي. Run with --dry-run first: it lists, per
 * class, the sessions it would create, update, cancel (something is attached) or remove (nothing attached), and the
 * ones it keeps because attendance was already recorded. Past sessions are never touched.
 */
class SessionsSyncCommand extends Command
{
    protected $signature = 'sessions:sync {--dry-run : Show what would change; write nothing}
        {--lesson=* : Only these class ids} {--weeks= : Weeks ahead (default: settings sessions.generate_weeks_ahead)}
        {--details : List every date, not only the totals per class}';

    protected $description = 'Sync class sessions with the timetable (preview with --dry-run)';

    public function handle(SessionSync $sync): int
    {
        $dry = (bool) $this->option('dry-run');
        $ids = array_map('intval', (array) $this->option('lesson')) ?: null;
        $weeks = $this->option('weeks') !== null ? (int) $this->option('weeks') : null;
        $plans = $sync->all($dry, $weeks, $ids);

        $this->line($dry ? '<comment>DRY RUN — nothing is written.</comment>' : 'Applied.');
        $this->table(['Class', 'Schedule', 'Create', 'Update', 'Cancel', 'Remove', 'Kept (attendance)'], array_map(fn ($p) => [
            "#{$p['lesson_id']} {$p['lesson']}", $p['legacy'] ? 'own days/times (legacy)' : 'timetable',
            count($p['create']), count($p['update']), count($p['cancel']), count($p['delete']), count($p['kept']),
        ], $plans));

        $sum = fn (string $k) => array_sum(array_map(fn ($p) => count($p[$k]), $plans));
        $this->line("Totals — create: {$sum('create')}, update: {$sum('update')}, cancel: {$sum('cancel')}, remove: {$sum('delete')}, kept: {$sum('kept')}");

        if ($this->option('details')) {
            foreach ($plans as $p) {
                foreach (['update', 'cancel', 'delete', 'kept'] as $k) {
                    foreach ($p[$k] as $row) {
                        $changes = isset($row['changes']) && $row['changes'] ? json_encode($row['changes']) : '';
                        $this->line(sprintf('  #%d %-8s %s session %s %s', $p['lesson_id'], $k, $row['date'], $row['session_id'], $changes));
                    }
                }
            }
        }
        if ($sum('cancel') > 0) {
            $this->warn('Cancelled sessions keep their confirmations, excuses and messages; planned reminders for them are withdrawn.');
        }

        return self::SUCCESS;
    }
}
