<?php

namespace App\Services\WhatsApp;

interface WhatsAppProvider
{
    /** Provider key stored in message_logs.provider */
    public function name(): string;

    /**
     * Send a text message. $phone is E.164 with "+".
     *
     * @return string provider message id
     *
     * @throws \App\Services\WhatsApp\WhatsAppException on failure (job will retry)
     */
    public function send(string $phone, string $body): string;

    /** @return array{connected: bool, detail: string|null} */
    public function status(): array;

    /** QR code (data URI or base64 PNG) when the session needs re-linking, otherwise null. */
    public function qr(): ?string;
}
