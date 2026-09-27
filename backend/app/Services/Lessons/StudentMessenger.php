<?php

namespace App\Services\Lessons;

use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Models\Student;
use App\Services\Messaging\MessageService;

/** Sends one message to the student's own phone and one to the guardian, deduplicating identical numbers. */
class StudentMessenger
{
    public function __construct(private MessageService $messages) {}

    /** @return int number of messages queued */
    public function notify(Student $student, MessageType $type, array $vars): int
    {
        $sent = [];
        $locale = $student->locale?->value ?? 'ar';

        $targets = [
            [$student->student_phone, RecipientType::Student],
            [$student->guardian_phone, RecipientType::Guardian],
        ];

        foreach ($targets as [$phone, $recipient]) {
            if (! $phone) {
                continue;
            }
            $normalized = \App\Support\PhoneNumber::normalize($phone);
            if (! $normalized || isset($sent[$normalized])) {
                continue;
            }
            $sent[$normalized] = true;
            $this->messages->send(phone: $normalized, type: $type, vars: $vars, locale: $locale, student: $student, recipientType: $recipient);
        }

        return count($sent);
    }
}
