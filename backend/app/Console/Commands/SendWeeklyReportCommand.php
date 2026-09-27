<?php

namespace App\Console\Commands;

use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Models\User;
use App\Services\Messaging\MessageService;
use App\Services\Reports\AttendanceReport;
use App\Services\Reports\EngagementReport;
use App\Services\Reports\EvaluationReport;
use App\Services\Reports\FinanceReport;
use App\Support\WeekDays;
use Illuminate\Console\Command;

/**
 * Weekly automatic report (section 11): on the configured weekday (setting reminders.weekly_report_day) every active
 * staff member with reports.view gets one WhatsApp summary of the last seven days, built from the same reports as the
 * reports screen and therefore limited to their own gender track. Sent once per person per week.
 */
class SendWeeklyReportCommand extends Command
{
    protected $signature = 'reports:weekly {--force : Send today whatever the configured weekday} {--dry-run : Print the messages instead of sending}';

    protected $description = 'Send the weekly summary report to admins and supervisors on WhatsApp';

    public function handle(MessageService $messages): int
    {
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $today = now($tz);
        $day = (string) setting('reminders.weekly_report_day', 'thu');
        if (! $this->option('force') && WeekDays::keyFor($today) !== $day) {
            $this->info("Not the weekly report day ({$day}).");

            return self::SUCCESS;
        }

        $range = ['from' => $today->copy()->subDays(6)->toDateString(), 'to' => $today->toDateString()];
        $sent = 0;
        $previous = app()->getLocale();

        $recipients = User::where('is_active', true)->whereNotNull('phone')->get()->filter(fn (User $u) => $u->can('reports.view'));
        foreach ($recipients as $user) {
            $locale = $user->locale?->value ?? 'ar';
            app()->setLocale($locale);
            try {
                $body = $this->body($user, $range);
            } finally {
                app()->setLocale($previous);
            }
            if ($this->option('dry-run')) {
                $this->line("--- {$user->phone} ({$locale})\n{$body}");

                continue;
            }
            $log = $messages->send(
                phone: $user->phone, type: MessageType::WeeklyReport, vars: ['body' => $body], locale: $locale,
                user: $user, recipientType: RecipientType::User, dedupeKey: 'weekly_report:'.$user->id.':'.$range['to'],
            );
            $sent += $log ? 1 : 0;
        }

        $this->info("Weekly reports queued: {$sent}");

        return self::SUCCESS;
    }

    /** Plain text, one "label: value" line per headline figure, grouped by report. */
    public function body(User $user, array $range): string
    {
        $lines = [__('reports.weekly.period', ['from' => $range['from'], 'to' => $range['to']])];
        $reports = [app(AttendanceReport::class), app(EvaluationReport::class), app(EngagementReport::class)];
        if ($user->can('wallets.view')) {
            $reports[] = app(FinanceReport::class);
        }
        foreach ($reports as $report) {
            $r = $report->build($user, $range);
            $lines[] = '';
            $lines[] = '• '.$r['title'];
            foreach (array_slice($r['summary'] ?? [], 0, 5) as [$label, $value]) {
                $lines[] = "{$label}: ".(is_int($value) ? number_format($value) : $value);
            }
        }
        $lines[] = '';
        $lines[] = __('reports.weekly.more');

        return implode("\n", $lines);
    }
}
