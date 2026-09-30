<?php

use App\Models\Lesson;
use App\Models\Level;
use App\Models\Subject;

it('seeds Quran as a protected system subject', function () {
    actingAsRole('supervisor');
    $quran = Subject::where('code', Subject::QURAN)->firstOrFail();
    expect($quran->is_system)->toBeTrue();

    $this->deleteJson("/api/subjects/{$quran->id}")->assertUnprocessable()->assertJsonValidationErrors('subject');
    $this->putJson("/api/subjects/{$quran->id}", ['name_ar' => 'القرآن الكريم', 'name_en' => 'Quran', 'code' => 'q'])->assertJsonValidationErrors('code');
    $this->putJson("/api/subjects/{$quran->id}", ['name_ar' => 'القرآن الكريم', 'name_en' => 'Quran', 'is_active' => false])->assertJsonValidationErrors('is_active');
    // Renaming is allowed.
    $this->putJson("/api/subjects/{$quran->id}", ['name_ar' => 'القرآن الكريم', 'name_en' => 'The Holy Quran', 'code' => 'quran'])->assertOk()->assertJsonPath('data.name_en', 'The Holy Quran');
});

it('lets supervisors manage subjects and teachers only list them', function () {
    actingAsRole('supervisor');
    $id = $this->postJson('/api/subjects', ['name_ar' => 'الفقه', 'name_en' => 'Fiqh', 'code' => 'fiqh'])->assertCreated()->json('data.id');
    $this->postJson('/api/subjects', ['name_ar' => 'الفقه', 'name_en' => 'Fiqh 2'])->assertJsonValidationErrors('name_ar');
    $this->putJson("/api/subjects/{$id}", ['name_ar' => 'الفقه', 'name_en' => 'Fiqh', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    actingAsRole('teacher');
    expect($this->getJson('/api/subjects')->assertOk()->json('data.*.code'))->toBe(['quran', 'fiqh'])
        ->and($this->getJson('/api/subjects?active=1')->json('data.*.code'))->toBe(['quran']);
    $this->postJson('/api/subjects', ['name_ar' => 'السيرة', 'name_en' => 'Seerah'])->assertForbidden();
    $this->deleteJson("/api/subjects/{$id}")->assertForbidden();

    actingAsRole('super_admin');
    $this->deleteJson("/api/subjects/{$id}")->assertOk();
});

it('manages levels and links circles to them without changing circles that have none', function () {
    actingAsRole('supervisor');
    $level = $this->postJson('/api/levels', ['name_ar' => 'المستوى الأول', 'name_en' => 'Level 1', 'code' => 'L1', 'sort' => 1])->assertCreated()->json('data');

    $withLevel = Lesson::factory()->create(['level_id' => $level['id']]);
    $plain = Lesson::factory()->create();

    expect($this->getJson("/api/lessons?level_id={$level['id']}")->json('data.*.id'))->toBe([$withLevel->id])
        ->and($this->getJson('/api/lessons?level_id=0')->json('data.*.id'))->toBe([$plain->id])
        ->and($this->getJson("/api/lessons/{$withLevel->id}")->json('data.level.name'))->toBe('المستوى الأول')
        ->and($this->getJson("/api/lessons/{$plain->id}")->json('data.level'))->toBeNull()
        ->and($this->getJson('/api/levels')->json('data.0.lessons_count'))->toBe(1);

    // A circle's level can be changed or cleared on update.
    $this->putJson("/api/lessons/{$plain->id}", ['level_id' => $level['id']])->assertOk()->assertJsonPath('data.level_id', $level['id']);
    $this->putJson("/api/lessons/{$plain->id}", ['level_id' => null])->assertOk()->assertJsonPath('data.level_id', null);
    $this->putJson("/api/lessons/{$plain->id}", ['level_id' => 999])->assertJsonValidationErrors('level_id');

    $this->deleteJson("/api/levels/{$level['id']}")->assertUnprocessable()->assertJsonValidationErrors('level');
    Level::find($level['id'])->lessons()->update(['level_id' => null]);
    $this->deleteJson("/api/levels/{$level['id']}")->assertOk();

    actingAsRole('teacher');
    $this->getJson('/api/levels')->assertOk();
    $this->postJson('/api/levels', ['name_ar' => 'x', 'name_en' => 'x'])->assertForbidden();
});

it('gives the new permissions to super admins and supervisors only', function () {
    $supervisor = \Spatie\Permission\Models\Role::findByName('supervisor', 'web');
    $teacher = \Spatie\Permission\Models\Role::findByName('teacher', 'web');
    $admin = \Spatie\Permission\Models\Role::findByName('super_admin', 'web');

    expect($admin->hasPermissionTo('terms.manage'))->toBeTrue()
        ->and($supervisor->hasPermissionTo('levels.manage'))->toBeTrue()
        ->and($supervisor->hasPermissionTo('subjects.manage'))->toBeTrue()
        ->and($supervisor->hasPermissionTo('terms.manage'))->toBeFalse()
        ->and($teacher->hasPermissionTo('levels.manage'))->toBeFalse();
});
