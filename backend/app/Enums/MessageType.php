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
    case CertificateIssued = 'certificate_issued';
    case Custom = 'custom';

    // Automatic attendance messaging (section 23); the value is also the template key.
    case AttendanceReminderLong = 'attendance_reminder_long';
    case AttendanceReminderShort = 'attendance_reminder_short';
    case AbsenceNotice = 'absence_notice';
    case RepeatedAbsence = 'repeated_absence';
    case SessionCancelled = 'session_cancelled';
    case ExcuseReceived = 'excuse_received';
    case NotificationsStopped = 'notifications_stopped';
    case NotificationsResumed = 'notifications_resumed';
    case AutoReplyGeneric = 'auto_reply_generic';

    // Honor board, competitions and challenges (sections 13–14); templates in EngagementTemplateSeeder.
    case HonorCongrats = 'honor_congrats';
    case CompetitionOpen = 'competition_open';
    case CompetitionClosing = 'competition_closing';
    case CompetitionRound = 'competition_round';
    case CompetitionResult = 'competition_result';
    case ChallengeNudge = 'challenge_nudge';
    case ChallengeDeadline = 'challenge_deadline';
    case ChallengeCompleted = 'challenge_completed';

    /**
     * Whether a recipient's opt-out (users.notifications_enabled, "إيقاف") and an invalid number block it.
     * Login codes always go out; direct replies to something the person just sent are answers, not notifications.
     */
    public function respectsOptOut(): bool
    {
        return ! in_array($this, [self::Otp, self::NotificationsStopped, self::NotificationsResumed, self::ExcuseReceived, self::AutoReplyGeneric], true);
    }

    /** Attendance messages governed by quiet hours and per-session de-duplication. */
    public function isAttendanceMessage(): bool
    {
        return in_array($this, [
            self::AttendanceReminderLong, self::AttendanceReminderShort, self::AbsenceNotice, self::RepeatedAbsence,
            self::LocationChange, self::SessionCancelled, self::ExcuseReceived, self::NotificationsStopped,
            self::NotificationsResumed, self::AutoReplyGeneric,
        ], true);
    }

    public function isReminder(): bool
    {
        return $this === self::AttendanceReminderLong || $this === self::AttendanceReminderShort;
    }
}
