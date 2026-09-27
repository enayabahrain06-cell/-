<?php

use App\Http\Controllers\Api\Wallet\PaymentController;
use App\Http\Controllers\Api\Wallet\WalletController;
use Illuminate\Support\Facades\Route;

// Wallet, invoices, payments, refunds (inside auth:sanctum group)
Route::get('students/{student}/wallet', [WalletController::class, 'show']);
Route::post('students/{student}/wallet/adjust', [WalletController::class, 'adjust']);
Route::get('me/wallet', [WalletController::class, 'me']);

Route::get('invoices', [WalletController::class, 'invoices']);
Route::post('invoices', [WalletController::class, 'storeInvoice']);
Route::post('invoices/{invoice}/cancel', [WalletController::class, 'cancelInvoice']);

Route::get('payments', [PaymentController::class, 'index']);
Route::post('payments', [PaymentController::class, 'store']);
Route::get('payments/{payment}', [PaymentController::class, 'show']);
Route::get('payments/{payment}/receipt.pdf', [PaymentController::class, 'receiptPdf']);
Route::post('payments/{payment}/resend-receipt', [PaymentController::class, 'resendReceipt']);

Route::get('refunds', [PaymentController::class, 'refunds']);
Route::post('refunds', [PaymentController::class, 'storeRefund']);
