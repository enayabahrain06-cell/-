<?php

namespace App\Services\Messaging;

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Jobs\SendWhatsAppMessage;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;

/**
 * Every outgoing WhatsApp message goes through here: template → message_logs row → queued job.
 * Messages are spaced 3–5 s apart (WHATSAPP_DELAY_MIN/MAX) by reserving send slots in the cache,
 * which works on the database cache driver as well as Redis.
 */
class MessageService
{
    public function __construct(private TemplateRenderer $renderer) {}

    public function send(
        string $phone,
        MessageType $type,
        array $vars = [],
        ?string $locale = null,
        ?Student $student = null,
        ?User $user = null,
        RecipientType $recipientType = RecipientType::Guardian,
        bool $immediate = false,
        ?string $templateKey = null,
    ): ?MessageLog {
        $phone = PhoneNumber::normalize($phone);
        if (! $phone) {
            return null;
        }

        $locale ??= $student?->locale?->value ?? $user?->locale?->value ?? 'ar';
        $templateKey ??= $type->value;
        $body = $this->renderer->render($templateKey, $vars + ['name' => $student?->full_name ?? $user?->name ?? ''], $locale);

        $log = MessageLog::create([
            'recipient_phone' => $phone,
            'recipient_type' => $recipientType,
            'student_id' => $student?->id,
            'user_id' => $user?->id,
            'type' => $type,
            'template_key' => $templateKey,
            'locale' => $locale,
            'body' => $body,
            'status' => MessageStatus::Queued,
            'attempts' => 0,
        ]);

        $this->dispatch($log, $immediate);

        return $log;
    }

    /** Re-queue a failed (or any) message. */
    public function resend(MessageLog $log): MessageLog
    {
        $log->update(['status' => MessageStatus::Queued, 'error' => null]);
        $this->dispatch($log, false);

        return $log;
    }

    public function dispatch(MessageLog $log, bool $immediate): void
    {
        $delay = $immediate ? 0 : $this->reserveSlot();

        SendWhatsAppMessage::dispatch($log->id)
            ->onQueue(config('whatsapp.queue', 'whatsapp'))
            ->delay(now()->addSeconds($delay));
    }

    /** Seconds from now until this message's reserved send slot. */
    private function reserveSlot(): int
    {
        $min = (int) config('whatsapp.delay_min', 3);
        $max = max($min, (int) config('whatsapp.delay_max', 5));
        $lock = Cache::lock('whatsapp.slot.lock', 5);

        try {
            $lock->block(5);
            $next = max((int) Cache::get('whatsapp.next_slot', 0), time());
            $gap = random_int($min, $max);
            Cache::put('whatsapp.next_slot', $next + $gap, now()->addHours(6));

            return max(0, $next - time());
        } finally {
            optional($lock)->release();
        }
    }
}
