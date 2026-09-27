<?php

namespace App\Http\Controllers\Api\Messages;

use App\Http\Controllers\Controller;
use App\Services\Messaging\InboundMessageHandler;
use App\Services\WhatsApp\Inbound\InboundEvent;
use App\Services\WhatsApp\Inbound\InboundParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @group Messages — WhatsApp inbound webhook
 *
 * Public endpoint for the WhatsApp provider (open-wa bridge or Cloud API). Every request must prove it
 * knows WHATSAPP_INBOUND_SECRET (X-Webhook-Secret header or ?token=), or carry a valid Cloud API
 * X-Hub-Signature-256; with no secret configured the endpoint is disabled.
 */
class WhatsAppWebhookController extends Controller
{
    /** Cloud API subscription check: echo hub.challenge when hub.verify_token matches the secret. */
    public function verify(Request $request): Response
    {
        $secret = (string) config('whatsapp.inbound.secret');
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));

        abort_if($secret === '' || ! hash_equals($secret, $token), 403);

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge', '')), 200, ['Content-Type' => 'text/plain']);
    }

    /** Inbound messages and delivery receipts. Answers {received, messages, acks}. */
    public function receive(Request $request, InboundParser $parser, InboundMessageHandler $handler): JsonResponse
    {
        $secret = (string) config('whatsapp.inbound.secret');
        if ($secret === '') {
            return response()->json(['message' => 'inbound disabled'], 503);
        }
        if (! $this->authorized($request, $secret)) {
            return response()->json(['message' => __('api.unauthenticated')], 401);
        }

        $events = $parser->parse($request->json()->all() ?: $request->all());
        $messages = 0;
        $acks = 0;
        foreach ($events as $event) {
            $handler->handle($event, $request->header('X-Provider', config('whatsapp.provider')));
            $event->kind === InboundEvent::ACK ? $acks++ : $messages++;
        }

        return response()->json(['received' => count($events), 'messages' => $messages, 'acks' => $acks]);
    }

    private function authorized(Request $request, string $secret): bool
    {
        $given = (string) ($request->header('X-Webhook-Secret') ?? $request->query('token', ''));
        if ($given !== '' && hash_equals($secret, $given)) {
            return true;
        }

        $appSecret = (string) config('whatsapp.inbound.cloud_app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        return $appSecret !== '' && $signature !== ''
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $appSecret), $signature);
    }
}
