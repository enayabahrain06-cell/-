<?php

use Ahl\Certificates\Http\Controllers\CertificateController;
use Ahl\Certificates\Http\Controllers\RecipientCertificateController;
use Ahl\Certificates\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

// Authenticated (certificates.routes.middleware).
Route::get('certificates/options', [CertificateController::class, 'options'])->name('certificates.options');
Route::get('certificates', [CertificateController::class, 'index'])->name('certificates.index');
Route::post('certificates', [CertificateController::class, 'store'])->name('certificates.store');
Route::post('certificates/approve', [CertificateController::class, 'approveMany'])->name('certificates.approve-many');
Route::get('certificates/recipients/{type}/{id}', [RecipientCertificateController::class, 'index'])->name('certificates.recipient');
Route::get('certificates/{certificate}', [CertificateController::class, 'show'])->whereNumber('certificate')->name('certificates.show');
Route::put('certificates/{certificate}', [CertificateController::class, 'update'])->whereNumber('certificate')->name('certificates.update');
Route::delete('certificates/{certificate}', [CertificateController::class, 'destroy'])->whereNumber('certificate')->name('certificates.destroy');
Route::post('certificates/{certificate}/approve', [CertificateController::class, 'approve'])->whereNumber('certificate')->name('certificates.approve');
Route::post('certificates/{certificate}/revoke', [CertificateController::class, 'revoke'])->whereNumber('certificate')->name('certificates.revoke');
Route::post('certificates/{certificate}/send', [CertificateController::class, 'send'])->whereNumber('certificate')->name('certificates.send');
Route::get('certificates/{certificate}/pdf', [CertificateController::class, 'pdf'])->whereNumber('certificate')->name('certificates.pdf');

Route::prefix('certificate-templates')->name('certificates.templates.')->group(function () {
    Route::get('/', [TemplateController::class, 'index'])->name('index');
    Route::put('{type}', [TemplateController::class, 'update'])->name('update');
    Route::get('{type}/preview', [TemplateController::class, 'preview'])->name('preview');
    Route::get('{type}/signatures/{slot}', [TemplateController::class, 'signature'])->whereNumber('slot')->name('signature');
    Route::post('{type}/signatures/{slot}', [TemplateController::class, 'storeSignature'])->whereNumber('slot')->name('signature.store');
    Route::delete('{type}/signatures/{slot}', [TemplateController::class, 'destroySignature'])->whereNumber('slot')->name('signature.destroy');
});
