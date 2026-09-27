<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;

it('lists audit entries with filters and labels, for audit.view only', function () {
    $boysSup = User::factory()->create(['track' => 'male', 'name' => 'مشرف البنين']);
    $girlsSup = User::factory()->create(['track' => 'female', 'name' => 'مشرفة البنات']);
    $log = app(AuditLogger::class);
    $log->record('wallet.adjusted', $boysSup, ['balance' => 0], ['balance' => 500], $boysSup->id);
    $log->record('wallet.refunded', $boysSup, [], ['amount' => 100], $boysSup->id);
    $log->record('package.created', $girlsSup, [], ['name' => 'باقة'], $girlsSup->id);

    actingAsRole('teacher');
    $this->getJson('/api/audit-logs')->assertForbidden();

    actingAsRole('super_admin', ['track' => 'both']);
    $this->getJson('/api/audit-logs')->assertOk()->assertJsonCount(3, 'data');
    $rows = $this->getJson('/api/audit-logs?action=wallet')->assertOk()->json('data');
    expect($rows)->toHaveCount(2)->and($rows[0]['action_label'])->toBe('استرداد');
    $this->getJson('/api/audit-logs?action=wallet.adjusted')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.new_values.balance', 500)->assertJsonPath('data.0.user.name', 'مشرف البنين');
    $this->getJson("/api/audit-logs?user_id={$girlsSup->id}")->assertOk()->assertJsonCount(1, 'data');
    expect(collect($this->getJson('/api/audit-logs/options')->json('data.groups'))->pluck('value')->all())->toBe(['package', 'wallet']);
});

it('limits a single-track viewer to entries made by staff of that track', function () {
    $boysSup = User::factory()->create(['track' => 'male']);
    $girlsSup = User::factory()->create(['track' => 'female']);
    app(AuditLogger::class)->record('package.created', $boysSup, [], [], $boysSup->id);
    app(AuditLogger::class)->record('package.created', $girlsSup, [], [], $girlsSup->id);

    $viewer = actingAsRole('supervisor', ['track' => 'female', 'gender' => 'female']);
    $viewer->givePermissionTo('audit.view');
    $rows = $this->getJson('/api/audit-logs')->assertOk()->json('data');
    expect(collect($rows)->pluck('user.id')->all())->toBe([$girlsSup->id]);
    expect(AuditLog::count())->toBe(2);
});
