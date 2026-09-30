<?php

use App\Http\Controllers\Api\Attendance\AttendanceMonitorController;
use App\Http\Controllers\Api\Attendance\DivisionAttendanceController;
use App\Http\Controllers\Api\Attendance\StaffAttendanceController;
use Illuminate\Support\Facades\Route;

// الحضور (Phase 5), inside the auth:sanctum group.

// حضور التقسيم and مراقبة تسجيل حضور التقسيم (same attendances rows as the class sheet)
Route::get('attendance/divisions', [DivisionAttendanceController::class, 'index']);
Route::get('attendance/divisions/{division}/sessions/{session}', [DivisionAttendanceController::class, 'show']);
Route::put('attendance/divisions/{division}/sessions/{session}', [DivisionAttendanceController::class, 'save']);

// مراقبة تسجيل الحضور
Route::get('attendance/monitor', [AttendanceMonitorController::class, 'index']);
Route::post('attendance/monitor/{session}/remind', [AttendanceMonitorController::class, 'remind']);

// حضور المشرفين / عرض حضور المشرفين / عرض حضور المعلمين
Route::get('staff-attendance/day', [StaffAttendanceController::class, 'day']);
Route::put('staff-attendance/day', [StaffAttendanceController::class, 'save']);
Route::get('staff-attendance/summary', [StaffAttendanceController::class, 'summary']);
Route::get('staff-attendance/detail', [StaffAttendanceController::class, 'detail']);
Route::delete('staff-attendance/{staffAttendance}', [StaffAttendanceController::class, 'destroy'])->whereNumber('staffAttendance');
