<?php

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\MessageLog;

it('exposes the delivery timeline on a message log (scheduled, sent, delivered, read)', function () {
    actingAsRole('supervisor');
    $log = MessageLog::create([
        'recipient_phone' => '+97336001234', 'recipient_type' => 'guardian', 'type' => MessageType::Absence,
        'template_key' => 'absence', 'locale' => 'ar', 'body' => 'تجربة', 'status' => MessageStatus::Read, 'attempts' => 1,
        'scheduled_for' => now()->subHours(3), 'sent_at' => now()->subHours(2), 'delivered_at' => now()->subHour(), 'read_at' => now(),
    ]);

    $d = $this->getJson("/api/messages/logs/{$log->id}")->assertOk()->json('data');
    expect($d)->toHaveKeys(['scheduled_for', 'sent_at', 'delivered_at', 'read_at'])
        ->and($d['read_at'])->not->toBeNull()
        ->and(strtotime($d['scheduled_for']))->toBeLessThan(strtotime($d['read_at']));

    $row = $this->getJson('/api/messages/logs')->assertOk()->json('data.0');
    expect($row['delivered_at'])->not->toBeNull();
});
