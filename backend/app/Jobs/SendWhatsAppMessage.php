<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Models\MessageLog;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var array<int, int> */
    public array $backoff;

    public function __construct(public int $messageLogId)
    {
        $this->tries = (int) config('whatsapp.max_tries', 3);
        $this->backoff = config('whatsapp.backoff_seconds', [30, 120, 300]);
    }

    public function handle(WhatsAppManager $whatsapp): void
    {
        $log = MessageLog::find($this->messageLogId);
        if (! $log || $log->status === MessageStatus::Sent) {
            return;
        }

        $provider = $whatsapp->provider();
        $log->increment('attempts');

        try {
            $providerId = $provider->send($log->recipient_phone, $log->body);
        } catch (Throwable $e) {
            $log->update(['error' => mb_substr($e->getMessage(), 0, 2000), 'provider' => $provider->name()]);
            throw $e; // let the queue retry with backoff
        }

        $log->update([
            'status' => MessageStatus::Sent,
            'provider' => $provider->name(),
            'provider_message_id' => $providerId,
            'sent_at' => now(),
            'error' => null,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        MessageLog::where('id', $this->messageLogId)->update([
            'status' => MessageStatus::Failed->value,
            'error' => $e ? mb_substr($e->getMessage(), 0, 2000) : 'failed',
        ]);
    }
}
