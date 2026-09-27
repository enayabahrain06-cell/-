<?php

namespace App\Services\WhatsApp\Inbound;

use Carbon\Carbon;

/**
 * Turns any supported webhook payload into InboundEvents:
 *
 * 1. Generic / open-wa bridge (whatsapp/):
 *      {"event":"message","from":"97336000001@c.us","body":"حاضر","id":"...","timestamp":1790000000}
 *      {"event":"ack","id":"<provider message id>","ack":3}          (open-wa ack: -1 failed, 1 sent, 2 delivered, 3 read, 4 played)
 *      {"event":"ack","id":"...","status":"delivered"}
 *    Also accepted: "text"/"message" for the body, "phone" for the sender, or {"messages":[...]} batches.
 * 2. WhatsApp Cloud API (Meta): {"object":"whatsapp_business_account","entry":[{"changes":[{"value":{
 *      "messages":[{"from":"973…","id":"wamid…","timestamp":"…","type":"text","text":{"body":"…"}}],
 *      "statuses":[{"id":"wamid…","status":"delivered"}]}}]}]}
 *
 * Group messages, our own outgoing messages and non-text messages without a caption are ignored.
 */
class InboundParser
{
    private const ACK_LEVELS = [-1 => 'failed', 0 => null, 1 => 'sent', 2 => 'delivered', 3 => 'read', 4 => 'read'];

    /** @return list<InboundEvent> */
    public function parse(array $payload): array
    {
        if (isset($payload['entry']) && is_array($payload['entry'])) {
            return $this->cloud($payload);
        }

        if (isset($payload['messages']) && is_array($payload['messages']) && array_is_list($payload['messages'])) {
            return array_values(array_merge(...array_map(fn ($m) => is_array($m) ? $this->generic($m) : [], $payload['messages'] ?: [[]])));
        }

        return $this->generic($payload);
    }

    /** @return list<InboundEvent> */
    private function generic(array $p): array
    {
        $event = strtolower((string) ($p['event'] ?? $p['type_event'] ?? 'message'));

        if ($event === 'ack' || $event === 'status' || isset($p['ack'])) {
            $id = (string) ($p['id'] ?? $p['message_id'] ?? '');
            $status = isset($p['status']) ? strtolower((string) $p['status']) : (self::ACK_LEVELS[(int) ($p['ack'] ?? 0)] ?? null);

            return $id !== '' && $status ? [InboundEvent::ack($id, $status, $p['error'] ?? null)] : [];
        }

        if (! empty($p['isGroupMsg']) || ! empty($p['fromMe']) || str_ends_with((string) ($p['from'] ?? ''), '@g.us')) {
            return [];
        }

        $from = (string) ($p['from'] ?? $p['phone'] ?? $p['sender'] ?? '');
        $body = $p['body'] ?? $p['text'] ?? $p['message'] ?? $p['caption'] ?? null;
        if (is_array($body)) {
            $body = $body['body'] ?? null;
        }
        $type = strtolower((string) ($p['type'] ?? 'chat'));
        if (! in_array($type, ['chat', 'text', 'buttons_response', 'list_response'], true) && empty($p['caption'])) {
            $body = null;
        }

        if ($from === '' || $body === null || trim((string) $body) === '') {
            return [];
        }

        return [InboundEvent::message($this->phone($from), (string) $body, isset($p['id']) ? (string) (is_array($p['id']) ? ($p['id']['_serialized'] ?? json_encode($p['id'])) : $p['id']) : null, $this->time($p['timestamp'] ?? $p['t'] ?? null))];
    }

    /** @return list<InboundEvent> */
    private function cloud(array $payload): array
    {
        $out = [];
        foreach ($payload['entry'] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                foreach ($value['messages'] ?? [] as $m) {
                    $body = $m['text']['body']
                        ?? $m['button']['text']
                        ?? $m['interactive']['button_reply']['title']
                        ?? $m['interactive']['list_reply']['title']
                        ?? $m['image']['caption'] ?? null;
                    if (! empty($m['from']) && $body !== null && trim((string) $body) !== '') {
                        $out[] = InboundEvent::message($this->phone((string) $m['from']), (string) $body, $m['id'] ?? null, $this->time($m['timestamp'] ?? null));
                    }
                }
                foreach ($value['statuses'] ?? [] as $s) {
                    if (! empty($s['id']) && ! empty($s['status'])) {
                        $out[] = InboundEvent::ack((string) $s['id'], strtolower((string) $s['status']), $s['errors'][0]['title'] ?? null);
                    }
                }
            }
        }

        return $out;
    }

    /** "97336000001@c.us" → "97336000001" (normalised to E.164 by the handler). */
    private function phone(string $raw): string
    {
        return explode('@', $raw)[0];
    }

    private function time(mixed $ts): ?\DateTimeInterface
    {
        if ($ts === null || $ts === '') {
            return null;
        }

        return is_numeric($ts) ? Carbon::createFromTimestamp((int) $ts) : Carbon::parse((string) $ts);
    }
}
