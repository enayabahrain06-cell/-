<?php

use App\Http\Controllers\Api\Certificates\CertificateController;
use App\Http\Controllers\Api\Certificates\PublicCertificateController;
use Illuminate\Support\Facades\Route;

// Public (no login): QR verification, and signed temporary PDF links handed out by the API.
Route::get('public/certificates/verify/{token}', [PublicCertificateController::class, 'verify'])->middleware('throttle:30,1');
Route::get('certificates/{certificate}/download', [CertificateController::class, 'download'])
    ->whereNumber('certificate')->middleware(['signed', 'throttle:60,1'])->name('certificates.download');
