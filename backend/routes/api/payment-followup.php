<?php

use App\Http\Controllers\Api\Wallet\PaymentFollowupController;
use Illuminate\Support\Facades\Route;

// متابعة الدفع (wallets.view), inside the auth:sanctum group.
Route::get('payment-followup', [PaymentFollowupController::class, 'index']);
