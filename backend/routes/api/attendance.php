<?php

use App\Http\Controllers\Api\Attendance\AttendanceController;
use App\Http\Controllers\Api\Attendance\MyAttendanceController;
use Illuminate\Support\Facades\Route;

Route::get('sessions/{session}/attendance', [AttendanceController::class, 'show']);
Route::put('sessions/{session}/attendance', [AttendanceController::class, 'save']);
Route::post('sessions/{session}/attendance/mark-all-present', [AttendanceController::class, 'markAllPresent']);

// Student / guardian portal
Route::get('me/attendance', [MyAttendanceController::class, 'index']);
