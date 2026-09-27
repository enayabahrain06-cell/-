<?php

namespace App\Services\WhatsApp;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the small open-wa/wa-automate-nodejs service in /whatsapp:
 *   POST /send   {to, text}   -> {id}
 *   GET  /status              -> {connected, state}
 *   GET  /qr                  -> {qr: "data:image/png;base64,..."} | 204
 */
class OpenWaProvider implements WhatsAppProvider
{
    public function name(): string
    {
        return 'openwa';
    }

    private function client()
    {
        return Http::baseUrl(config('whatsapp.openwa.base_url'))
            ->withHeaders(['x-api-key' => config('whatsapp.openwa.api_key')])
            ->timeout(config('whatsapp.openwa.timeout', 20))
            ->acceptJson();
    }

    public function send(string $phone, string $body): string
    {
        $response = $this->client()->post('/send', [
            'to' => PhoneNumber::forWhatsApp($phone),
            'text' => $body,
        ]);

        if (! $response->successful() || ! $response->json('id')) {
            throw new WhatsAppException('open-wa send failed: '.$response->status().' '.$response->body());
        }

        return (string) $response->json('id');
    }

    public function status(): array
    {
        try {
            $r = $this->client()->get('/status');

            return ['connected' => (bool) $r->json('connected'), 'detail' => (string) $r->json('state')];
        } catch (\Throwable $e) {
            return ['connected' => false, 'detail' => $e->getMessage()];
        }
    }

    public function qr(): ?string
    {
        try {
            $r = $this->client()->get('/qr');

            return $r->status() === 200 ? $r->json('qr') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
