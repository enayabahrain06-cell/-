<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum MessageType: string
{
    use HasLabel;

    case PreLessonReminder = 'pre_lesson_reminder';
    case Absence = 'absence';
    case LocationChange = 'location_change';
    case RegistrationReceived = 'registration_received';
    case RegistrationAccepted = 'registration_accepted';
    case RegistrationWaitlist = 'registration_waitlist';
    case RegistrationRejected = 'registration_rejected';
    case LotteryResult = 'lottery_result';
    case EvaluationResult = 'evaluation_result';
    case ExamReminder = 'exam_reminder';
    case ExamResult = 'exam_result';
    case PaymentReceipt = 'payment_receipt';
    case PaymentDueReminder = 'payment_due_reminder';
    case Otp = 'otp';
    case WeeklyReport = 'weekly_report';
    case StudentProgressUpdate = 'student_progress_update';
    case Custom = 'custom';
}
