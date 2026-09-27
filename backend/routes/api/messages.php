<?php

use App\Http\Controllers\Api\Messages\AttendanceMessagingController;
use App\Http\Controllers\Api\Messages\MessageLogController;
use App\Http\Controllers\Api\Messages\SendMessageController;
use App\Http\Controllers\Api\Messages\TemplateController;
use App\Http\Controllers\Api\Messages\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('messages')->group(function () {
    Route::get('templates', [TemplateController::class, 'index']);
    Route::put('templates/{template}', [TemplateController::class, 'update']);
    Route::get('templates/{template}/preview', [TemplateController::class, 'preview']);

    Route::get('logs', [MessageLogController::class, 'index']);
    Route::get('logs/{log}', [MessageLogController::class, 'show']);
    Route::post('logs/{log}/resend', [MessageLogController::class, 'resend']);
    Route::post('resend-failed', [MessageLogController::class, 'resendFailed']);
    Route::get('stats', [MessageLogController::class, 'stats']);

    Route::post('send', SendMessageController::class);
});

Route::prefix('whatsapp')->group(function () {
    Route::get('status', [WhatsAppController::class, 'status']);
    Route::get('qr', [WhatsAppController::class, 'qr']);
});

// Automatic attendance messaging screens (section 23)
Route::get('messages/rules', [AttendanceMessagingController::class, 'rules']);
Route::put('messages/rules', [AttendanceMessagingController::class, 'updateRules']);
Route::put('lessons/{lesson}/messaging-rule', [AttendanceMessagingController::class, 'lessonRule']);
Route::get('sessions/{session}/messages', [AttendanceMessagingController::class, 'session']);
Route::post('sessions/{session}/messages/send-now', [AttendanceMessagingController::class, 'sendNow']);
Route::get('messages/inbox', [AttendanceMessagingController::class, 'inbox']);
Route::post('messages/inbox/{inbound}/resolve', [AttendanceMessagingController::class, 'resolve']);
Route::get('attendance/excuses', [AttendanceMessagingController::class, 'excuses']);
Route::post('attendance/excuses/{excuse}/review', [AttendanceMessagingController::class, 'reviewExcuse']);
