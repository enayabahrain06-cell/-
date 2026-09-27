<?php

use App\Http\Controllers\Api\Messages\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Public, secret-protected: inbound WhatsApp replies and delivery receipts from the provider.
Route::prefix('public/whatsapp')->middleware('throttle:240,1')->group(function () {
    Route::get('inbound', [WhatsAppWebhookController::class, 'verify']);
    Route::post('inbound', [WhatsAppWebhookController::class, 'receive']);
});
