<?php

namespace App\Console\Commands;

use App\Services\Lessons\SessionSync;
use Illuminate\Console\Command;

/** Keep every class's sessions in step with الجدول الدراسي for the next N weeks (scheduled daily). */
class GenerateLessonSessionsCommand extends Command
{
    protected $signature = 'lesson-sessions:generate {--weeks= : Weeks ahead (default: settings sessions.generate_weeks_ahead)}';

    protected $description = 'Sync lesson_sessions with the timetable for the coming weeks (create, update, cancel; never delete attached sessions)';

    public function handle(SessionSync $sync): int
    {
        $weeks = $this->option('weeks') !== null ? (int) $this->option('weeks') : null;
        $plans = $sync->all(false, $weeks);
        $count = fn (string $k) => array_sum(array_map(fn ($p) => count($p[$k]), $plans));

        $this->info("Sessions created: {$count('create')}, updated: {$count('update')}, cancelled: {$count('cancel')}, removed: {$count('delete')}");

        return self::SUCCESS;
    }
}
