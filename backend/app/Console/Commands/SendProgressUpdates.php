<?php

namespace App\Console\Commands;

use App\Enums\EvaluationType;
use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Enums\StudentStatus;
use App\Models\MessageLog;
use App\Models\Student;
use App\Services\Evaluation\EvaluationService;
use App\Services\Messaging\MessageService;
use App\Services\Progress\ProgressService;
use Illuminate\Console\Command;

/**
 * Optional monthly WhatsApp update to guardians (template student_progress_update):
 * current position, % memorized, yearly plan, last-30-day averages and open difficulties.
 * Scheduled daily; sends only when messages.progress_update_enabled is on and today is
 * messages.progress_update_day. Each student gets at most one update per calendar month.
 */
class SendProgressUpdates extends Command
{
    protected $signature = 'progress:send-monthly-updates {--force : Ignore the enabled flag and the configured day} {--student=* : Limit to these student ids}';

    protected $description = 'Send the monthly progress update to guardians by WhatsApp';

    public function handle(ProgressService $progress, EvaluationService $evaluations, MessageService $messages): int
    {
        $force = (bool) $this->option('force');
        if (! $force && ! setting('messages.progress_update_enabled', false)) {
            $this->info('Monthly progress updates are disabled.');

            return self::SUCCESS;
        }
        if (! $force && (int) setting('messages.progress_update_day', 1) !== (int) now(config('ahl.display_timezone'))->day) {
            $this->info('Not the configured day.');

            return self::SUCCESS;
        }

        $monthStart = now(config('ahl.display_timezone'))->startOfMonth()->utc();
        $alreadySent = MessageLog::where('type', MessageType::StudentProgressUpdate->value)
            ->where('created_at', '>=', $monthStart)->pluck('student_id')->filter()->all();

        $sent = 0;
        Student::with(['guardian', 'wallet'])->where('status', StudentStatus::Active->value)
            ->when($this->option('student'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->whereNotIn('id', $alreadySent)
            ->chunkById(100, function ($students) use ($progress, $evaluations, $messages, &$sent) {
                foreach ($students as $student) {
                    if (! $student->guardian_phone) {
                        continue;
                    }
                    $locale = $student->guardian?->locale?->value ?? $student->locale?->value ?? 'ar';
                    $messages->send($student->guardian_phone, MessageType::StudentProgressUpdate,
                        $this->vars($student, $locale, $progress, $evaluations), $locale, $student, null, RecipientType::Guardian);
                    $sent++;
                }
            });

        $this->info("Progress updates queued: {$sent}");

        return self::SUCCESS;
    }

    public function vars(Student $student, string $locale, ProgressService $progress, EvaluationService $evaluations): array
    {
        $summary = $progress->summary($student, $locale);
        $current = $summary['position']['current'];

        $recent = $student->evaluations()->quran()->where('type', EvaluationType::Daily->value)
            ->where('evaluated_on', '>=', today()->subDays(30)->toDateString())->get();
        $avg = $recent->isEmpty() ? null : $evaluations->averages($recent);

        $issues = $student->issues()->unresolved()->get()
            ->map(fn ($i) => $i->category->label($locale))->unique()->implode($locale === 'en' ? ', ' : '، ');

        return [
            'position' => $current['label'] ?? __('progress.monthly_update.not_started', [], $locale),
            'percent' => $summary['quran_percent'].'%',
            'juz_count' => (string) $summary['completed_juz'],
            'plan_percent' => $summary['plan']['percent'] !== null ? $summary['plan']['percent'].'%' : '—',
            'averages' => $avg ? __('progress.monthly_update.averages', ['m' => $avg['memorization'], 't' => $avg['tajweed'], 'r' => $avg['revision'], 'b' => $avg['behavior']], $locale) : '—',
            'issues' => $issues !== '' ? $issues : __('progress.monthly_update.none', [], $locale),
        ];
    }
}
