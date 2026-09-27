<?php

namespace App\Services\Messaging;

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Jobs\SendWhatsAppMessage;
use App\Models\LessonSession;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every outgoing WhatsApp message goes through here: template → message_logs row → queued job.
 * Messages are spaced 3–5 s apart (WHATSAPP_DELAY_MIN/MAX) by reserving send slots in the cache,
 * which works on the database cache driver as well as Redis.
 *
 * Optional for attendance messaging: $session ties the row to a lesson session, $sendAt plans it for
 * later (status "scheduled", sent by ScheduledMessageDispatcher), and $dedupeKey (unique column)
 * guarantees the same message is never created twice. Recipients who stopped notifications or whose
 * number is invalid get a "suppressed" row instead of a send.
 */
class MessageService
{
    public function __construct(private TemplateRenderer $renderer, private DeliveryGuard $guard) {}

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
        ?LessonSession $session = null,
        ?CarbonInterface $sendAt = null,
        ?string $dedupeKey = null,
    ): ?MessageLog {
        $phone = PhoneNumber::normalize($phone);
        if (! $phone) {
            return null;
        }

        if ($dedupeKey !== null && MessageLog::where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $locale ??= $student?->locale?->value ?? $user?->locale?->value ?? 'ar';
        $templateKey ??= $type->value;
        $body = $this->renderer->render($templateKey, $vars + ['name' => $student?->full_name ?? $user?->name ?? ''], $locale);
        $scheduled = $sendAt !== null && $sendAt->greaterThan(now());

        $attributes = [
            'recipient_phone' => $phone,
            'recipient_type' => $recipientType,
            'student_id' => $student?->id,
            'user_id' => $user?->id,
            'lesson_session_id' => $session?->id,
            'type' => $type,
            'template_key' => $templateKey,
            'locale' => $locale,
            'body' => $body,
            'attempts' => 0,
            'dedupe_key' => $dedupeKey,
        ];

        // Planned messages are checked when they fall due; immediate ones now.
        $blocked = $scheduled ? null : $this->guard->blockedReason($phone, $type, $user);

        $log = $this->create($attributes + match (true) {
            $blocked !== null => ['status' => MessageStatus::Suppressed, 'error' => $blocked],
            $scheduled => ['status' => MessageStatus::Scheduled, 'scheduled_for' => $sendAt->copy()->utc()],
            default => ['status' => MessageStatus::Queued],
        });

        if (! $log || $blocked !== null) {
            return null;
        }

        if (! $scheduled) {
            $this->dispatch($log, $immediate);
        }

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

    /** Insert inside a savepoint so a lost de-duplication race does not poison the outer transaction (PostgreSQL). */
    private function create(array $attributes): ?MessageLog
    {
        if ($attributes['dedupe_key'] === null) {
            return MessageLog::create($attributes);
        }

        try {
            return DB::transaction(fn () => MessageLog::create($attributes));
        } catch (QueryException $e) {
            if (MessageLog::where('dedupe_key', $attributes['dedupe_key'])->exists()) {
                return null;
            }
            throw $e;
        }
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
