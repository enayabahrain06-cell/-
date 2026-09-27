<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Development provider: writes the message to the log and always succeeds. */
class LogProvider implements WhatsAppProvider
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $phone, string $body): string
    {
        $id = 'log-'.Str::uuid();
        Log::channel(config('logging.default'))->info("[WhatsApp:{$id}] to {$phone}\n{$body}");

        return $id;
    }

    public function status(): array
    {
        return ['connected' => true, 'detail' => 'log provider (development)'];
    }

    public function qr(): ?string
    {
        return null;
    }
}
