<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum AlertType: string
{
    use HasLabel;

    case LocationConflict = 'location_conflict';
    case RegistrationRequest = 'registration_request';
    case RepeatedAbsence = 'repeated_absence';
    case LotteryPending = 'lottery_pending';
    case ExamUpcoming = 'exam_upcoming';
    case InvoiceOverdue = 'invoice_overdue';
    case LessonWithoutTeacher = 'lesson_no_teacher'; // computed on the dashboard, never stored
}
