<?php

use App\Models\AcademicTerm;
use App\Models\Night;
use App\Models\NightSupervisor;
use App\Models\User;

it('stores one shared menu layout that only menu.manage can change', function () {
    actingAsRole('teacher');
    $this->getJson('/api/menu-layout')->assertOk()->assertJsonPath('data.sections', [])->assertJsonPath('data.hidden', []);
    $this->putJson('/api/menu-layout', ['sections' => [], 'entries' => [], 'hidden' => []])->assertForbidden();

    actingAsRole('super_admin');
    $layout = ['sections' => ['registration', 'system'], 'entries' => ['registration' => ['students', 'enrollment', 'students']], 'hidden' => ['lottery']];
    $this->putJson('/api/menu-layout', $layout)->assertOk()
        ->assertJsonPath('data.entries.registration', ['students', 'enrollment']); // duplicates dropped
    $this->putJson('/api/menu-layout', ['sections' => ['Bad Key!'], 'entries' => [], 'hidden' => []])->assertJsonValidationErrors('sections.0');

    actingAsRole('supervisor');
    expect($this->getJson('/api/menu-layout')->json('data.hidden'))->toBe(['lottery']);

    actingAsRole('guardian');
    $this->getJson('/api/menu-layout')->assertForbidden();
});

it('lists the seven nights and lets nights.manage switch them and set times', function () {
    actingAsRole('teacher');
    expect($this->getJson('/api/nights')->assertOk()->json('data.*.weekday'))->toBe(['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri']);
    $fri = Night::where('weekday', 'fri')->first();
    $this->putJson("/api/nights/{$fri->id}", ['is_active' => false])->assertForbidden();

    actingAsRole('supervisor');
    $this->putJson("/api/nights/{$fri->id}", ['is_active' => false, 'start_time' => '16:00', 'end_time' => '19:00'])->assertOk()
        ->assertJsonPath('data.is_active', false)->assertJsonPath('data.start_time', '16:00');
    $this->putJson("/api/nights/{$fri->id}", ['start_time' => '19:00', 'end_time' => '18:00'])->assertJsonValidationErrors('end_time');
});

it('lists supervisors with their nights in the selected term', function () {
    $term = AcademicTerm::create(['name_ar' => 'ف١', 'name_en' => 'T1', 'is_current' => true]);
    $sup = User::factory()->create(['name' => 'المشرف سعيد']);
    $sup->assignRole('supervisor');
    NightSupervisor::create(['academic_term_id' => $term->id, 'weekday' => 'wed', 'user_id' => $sup->id]);
    NightSupervisor::create(['academic_term_id' => $term->id, 'weekday' => 'sat', 'user_id' => $sup->id]);

    actingAsRole('super_admin');
    $row = collect($this->getJson('/api/master-data/supervisors')->assertOk()->json('data'))->firstWhere('id', $sup->id);
    expect($row['nights'])->toBe(['sat', 'wed']);

    actingAsRole('guardian');
    $this->getJson('/api/master-data/supervisors')->assertForbidden();
});
