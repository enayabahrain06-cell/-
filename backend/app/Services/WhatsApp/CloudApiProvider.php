<?php

namespace App\Services\WhatsApp;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;

/** Official WhatsApp Cloud API (Meta Graph). */
class CloudApiProvider implements WhatsAppProvider
{
    public function name(): string
    {
        return 'cloud';
    }

    public function send(string $phone, string $body): string
    {
        $cfg = config('whatsapp.cloud');
        $url = "https://graph.facebook.com/{$cfg['api_version']}/{$cfg['phone_number_id']}/messages";

        $response = Http::withToken($cfg['token'])->timeout($cfg['timeout'] ?? 20)->post($url, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => PhoneNumber::forWhatsApp($phone),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);

        $id = $response->json('messages.0.id');
        if (! $response->successful() || ! $id) {
            throw new WhatsAppException('Cloud API send failed: '.$response->status().' '.$response->body());
        }

        return (string) $id;
    }

    public function status(): array
    {
        $cfg = config('whatsapp.cloud');
        $configured = $cfg['token'] !== '' && $cfg['phone_number_id'] !== '';

        return ['connected' => $configured, 'detail' => $configured ? 'cloud api configured' : 'missing token or phone number id'];
    }

    public function qr(): ?string
    {
        return null;
    }
}
