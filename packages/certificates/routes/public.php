<?php

use Ahl\Certificates\Http\Controllers\CertificateController;
use Ahl\Certificates\Http\Controllers\VerifyController;
use Illuminate\Support\Facades\Route;

// Public (certificates.routes.public_middleware): QR verification and signed downloads.
Route::get('public/certificates/verify/{token}', VerifyController::class)->middleware('throttle:30,1')->name('certificates.verify');
Route::get('certificates/{certificate}/download', [CertificateController::class, 'download'])
    ->whereNumber('certificate')->middleware(['signed', 'throttle:60,1'])->name('certificates.download');
