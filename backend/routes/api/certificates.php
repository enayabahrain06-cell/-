<?php

use App\Http\Controllers\Api\Certificates\CertificateController;
use App\Http\Controllers\Api\Certificates\CertificateTemplateController;
use App\Http\Controllers\Api\Certificates\StudentCertificateController;
use Illuminate\Support\Facades\Route;

// Certificates (spec section 16). Loaded inside the auth:sanctum group.
Route::get('certificates/options', [CertificateController::class, 'options']);
Route::get('certificates', [CertificateController::class, 'index']);
Route::post('certificates', [CertificateController::class, 'store']);
Route::post('certificates/approve', [CertificateController::class, 'approveMany']);
Route::get('certificates/{certificate}', [CertificateController::class, 'show'])->whereNumber('certificate');
Route::put('certificates/{certificate}', [CertificateController::class, 'update'])->whereNumber('certificate');
Route::delete('certificates/{certificate}', [CertificateController::class, 'destroy'])->whereNumber('certificate');
Route::post('certificates/{certificate}/approve', [CertificateController::class, 'approve'])->whereNumber('certificate');
Route::post('certificates/{certificate}/revoke', [CertificateController::class, 'revoke'])->whereNumber('certificate');
Route::post('certificates/{certificate}/send', [CertificateController::class, 'send'])->whereNumber('certificate');
Route::get('certificates/{certificate}/pdf', [CertificateController::class, 'pdf'])->whereNumber('certificate')->name('certificates.pdf');
Route::get('students/{student}/certificates', [StudentCertificateController::class, 'index'])->whereNumber('student');

Route::prefix('certificate-templates')->group(function () {
    Route::get('/', [CertificateTemplateController::class, 'index']);
    Route::put('{type}', [CertificateTemplateController::class, 'update']);
    Route::get('{type}/preview', [CertificateTemplateController::class, 'preview'])->name('certificates.templates.preview');
    Route::get('{type}/signatures/{slot}', [CertificateTemplateController::class, 'signature'])->whereNumber('slot')->name('certificates.templates.signature');
    Route::post('{type}/signatures/{slot}', [CertificateTemplateController::class, 'storeSignature'])->whereNumber('slot');
    Route::delete('{type}/signatures/{slot}', [CertificateTemplateController::class, 'destroySignature'])->whereNumber('slot');
});
