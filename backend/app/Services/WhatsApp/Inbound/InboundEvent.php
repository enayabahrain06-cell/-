<?php

namespace App\Services\WhatsApp\Inbound;

/**
 * One provider-neutral webhook event: an incoming text message, or a delivery receipt (ack)
 * for a message we sent (status sent / delivered / read / failed).
 */
final class InboundEvent
{
    public const MESSAGE = 'message';

    public const ACK = 'ack';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $from = null,
        public readonly ?string $body = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?\DateTimeInterface $at = null,
        public readonly ?string $ackStatus = null,
        public readonly ?string $error = null,
    ) {}

    public static function message(string $from, string $body, ?string $id = null, ?\DateTimeInterface $at = null): self
    {
        return new self(self::MESSAGE, $from, $body, $id, $at);
    }

    public static function ack(string $id, string $status, ?string $error = null): self
    {
        return new self(self::ACK, providerMessageId: $id, ackStatus: $status, error: $error);
    }
}
