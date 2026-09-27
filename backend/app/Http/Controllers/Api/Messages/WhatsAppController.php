<?php

namespace App\Http\Controllers\Api\Messages;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Messages — WhatsApp session
 */
class WhatsAppController extends Controller
{
    /** Provider name and whether the session is connected. */
    public function status(Request $request, WhatsAppManager $whatsapp): JsonResponse
    {
        abort_unless($request->user()->can('whatsapp.status'), 403);

        $provider = $whatsapp->provider();

        return response()->json(['provider' => $provider->name()] + $provider->status());
    }

    /** QR code to re-link the session (open-wa only). 204 when none is pending. */
    public function qr(Request $request, WhatsAppManager $whatsapp): JsonResponse
    {
        abort_unless($request->user()->can('whatsapp.status'), 403);

        $qr = $whatsapp->provider()->qr();

        return $qr ? response()->json(['qr' => $qr]) : response()->json(null, 204);
    }
}
