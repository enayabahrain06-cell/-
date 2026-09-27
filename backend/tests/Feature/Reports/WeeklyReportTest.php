<?php

use App\Models\MessageLog;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;

it('sends the weekly report once, only on the configured day, to staff with reports.view', function () {
    $this->travelTo(Carbon::parse('2026-09-24 17:00:00', 'UTC')); // Thursday 20:00 in Bahrain
    app(SettingsService::class)->set('reminders.weekly_report_day', 'thu', 'reminders', 'string');
    $sup = User::factory()->create(['phone' => '+97336500001', 'track' => 'male', 'gender' => 'male']);
    $sup->assignRole('supervisor');
    $teacher = User::factory()->create(['phone' => '+97336500002']);
    $teacher->assignRole('teacher');

    $this->artisan('reports:weekly')->assertSuccessful();
    $this->artisan('reports:weekly')->assertSuccessful();
    $logs = MessageLog::where('type', 'weekly_report')->get();
    expect($logs->pluck('recipient_phone')->all())->toBe(['+97336500001'])
        ->and($logs->first()->body)->toContain('2026-09-18')->toContain('2026-09-24');

    MessageLog::query()->delete();
    $this->travelTo(Carbon::parse('2026-09-25 17:00:00', 'UTC')); // Friday
    $this->artisan('reports:weekly')->assertSuccessful();
    expect(MessageLog::where('type', 'weekly_report')->count())->toBe(0);
});
