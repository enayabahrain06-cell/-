<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('creates every domain table', function () {
    $tables = [
        'users', 'settings', 'media', 'audit_logs', 'otp_codes', 'locations', 'packages', 'teachers',
        'students', 'registration_requests', 'lessons', 'lesson_students', 'lesson_location_overrides',
        'location_bookings', 'lesson_sessions', 'attendances', 'evaluations', 'student_progress',
        'lotteries', 'lottery_teachers', 'lottery_students', 'lottery_results',
        'exams', 'exam_questions', 'exam_attempts', 'exam_answers', 'certificates',
        'wallets', 'invoices', 'payments', 'invoice_payments', 'wallet_transactions', 'refunds',
        'message_templates', 'message_logs', 'alerts',
        'permissions', 'roles', 'model_has_roles', 'personal_access_tokens', 'jobs', 'failed_jobs', 'cache',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("table {$table} is missing");
    }
});

it('rolls back and re-migrates cleanly', function () {
    Artisan::call('migrate:rollback', ['--force' => true]);
    expect(Schema::hasTable('students'))->toBeFalse();

    Artisan::call('migrate', ['--force' => true]);
    expect(Schema::hasTable('students'))->toBeTrue();
});
