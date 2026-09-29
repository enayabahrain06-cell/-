<?php

namespace App\Console\Commands;

use App\Enums\ExamStatus;
use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Models\Exam;
use App\Services\Exams\ExamService;
use App\Services\Messaging\MessageService;
use Illuminate\Console\Command;

/**
 * Runs every 5 minutes. Sends the "one day before" and "one hour before" WhatsApp reminders
 * to eligible students (student + guardian phones) for published exams.
 */
class SendExamReminders extends Command
{
    protected $signature = 'exams:send-reminders';

    protected $description = 'Send WhatsApp reminders one day and one hour before each published exam opens';

    public function handle(ExamService $exams, MessageService $messages): int
    {
        $now = now();
        $sent = 0;

        $dayExams = Exam::forStudents()->where('status', ExamStatus::Published->value)->whereNull('reminder_day_sent_at')
            ->whereBetween('opens_at', [$now->copy()->addHours(23)->addMinutes(50), $now->copy()->addHours(24)->addMinutes(10)])->get();
        $hourExams = Exam::forStudents()->where('status', ExamStatus::Published->value)->whereNull('reminder_hour_sent_at')
            ->whereBetween('opens_at', [$now->copy()->addMinutes(55), $now->copy()->addMinutes(65)])->get();

        foreach ([['reminder_day_sent_at', $dayExams], ['reminder_hour_sent_at', $hourExams]] as [$column, $list]) {
            foreach ($list as $exam) {
                foreach ($exams->eligibleStudents($exam) as $student) {
                    $locale = $student->locale?->value ?? 'ar';
                    $opens = display_tz($exam->opens_at);
                    $vars = ['lesson' => $exam->name, 'date' => $opens->format('Y-m-d'), 'time' => $opens->format('H:i')];
                    foreach (array_unique(array_filter([$student->guardian_phone, $student->student_phone])) as $phone) {
                        $messages->send($phone, MessageType::ExamReminder, $vars, $locale, $student, null, $phone === $student->guardian_phone ? RecipientType::Guardian : RecipientType::Student);
                        $sent++;
                    }
                }
                $exam->update([$column => $now]);
            }
        }

        $this->info("Exam reminders queued: {$sent}");

        return self::SUCCESS;
    }
}
