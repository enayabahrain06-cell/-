<?php

namespace App\Console\Commands;

use App\Services\Lessons\SessionGenerator;
use Illuminate\Console\Command;

/** Materialise lesson sessions for the next N weeks (scheduled daily). */
class GenerateLessonSessionsCommand extends Command
{
    protected $signature = 'lesson-sessions:generate {--weeks= : Weeks ahead (default: settings sessions.generate_weeks_ahead)}';

    protected $description = 'Generate lesson_sessions rows for every active lesson for the coming weeks';

    public function handle(SessionGenerator $generator): int
    {
        $weeks = $this->option('weeks') !== null ? (int) $this->option('weeks') : null;
        $created = $generator->generateAll($weeks);

        $this->info("Sessions created: {$created}");

        return self::SUCCESS;
    }
}
