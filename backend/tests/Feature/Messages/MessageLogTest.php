<?php

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\MessageLog;
use App\Models\Student;

function failedLog(array $extra = []): MessageLog
{
    return MessageLog::create(array_merge([
        'recipient_phone' => '+97336001234',
        'recipient_type' => 'guardian',
        'type' => MessageType::Absence,
        'template_key' => 'absence',
        'locale' => 'ar',
        'body' => 'تجربة',
        'status' => MessageStatus::Failed,
        'attempts' => 3,
        'error' => 'boom',
    ], $extra));
}

it('lists and filters logs', function () {
    actingAsRole('supervisor');
    $student = Student::factory()->create();
    failedLog(['student_id' => $student->id]);
    failedLog(['status' => MessageStatus::Sent, 'type' => MessageType::Otp]);

    $this->getJson('/api/messages/logs')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/messages/logs?status=failed')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.student_name', $student->full_name);
    $this->getJson('/api/messages/logs?type=otp')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/messages/logs?phone=36001234')->assertOk()->assertJsonCount(2, 'data');
});

it('resends a failed message (queued then sent on the sync queue)', function () {
    actingAsRole('supervisor');
    $log = failedLog();

    $this->postJson("/api/messages/logs/{$log->id}/resend")->assertOk()->assertJsonPath('data.status', 'sent');
    expect($log->fresh()->status)->toBe(MessageStatus::Sent)->and($log->fresh()->error)->toBeNull();
});

it('re-queues all failed messages', function () {
    actingAsRole('supervisor');
    failedLog();
    failedLog();
    failedLog(['status' => MessageStatus::Sent]);

    $this->postJson('/api/messages/resend-failed')->assertOk()->assertJsonPath('count', 2);
    expect(MessageLog::where('status', 'failed')->count())->toBe(0);
});

it('returns counts by status and type', function () {
    actingAsRole('supervisor');
    failedLog();
    failedLog(['status' => MessageStatus::Sent, 'type' => MessageType::Otp]);

    $this->getJson('/api/messages/stats')->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('by_status.failed', 1)
        ->assertJsonPath('by_type.otp', 1);
});

it('forbids teachers from the logs', function () {
    actingAsRole('teacher');
    $this->getJson('/api/messages/logs')->assertForbidden();
});
