<?php

use App\Models\User;

it('logs a staff user in with phone and password', function () {
    $user = User::factory()->create(['phone' => '+97336000010']);
    $user->assignRole('supervisor');

    $this->postJson('/api/auth/login', ['phone' => '36000010', 'password' => 'password'])
        ->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'roles', 'permissions']])
        ->assertJsonPath('user.roles.0', 'supervisor');
});

it('accepts Arabic-Indic digits and local formats in the phone field', function () {
    $user = User::factory()->create(['phone' => '+97336000011']);
    $user->assignRole('teacher');

    $this->postJson('/api/auth/login', ['phone' => '٠٣٦٠٠٠٠١١', 'password' => 'password'])->assertOk();
});

it('rejects a wrong password', function () {
    $user = User::factory()->create(['phone' => '+97336000012']);
    $user->assignRole('teacher');

    $this->postJson('/api/auth/login', ['phone' => '+97336000012', 'password' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

it('refuses password login for students and guardians', function () {
    $user = User::factory()->create(['phone' => '+97336000013']);
    $user->assignRole('guardian');

    $this->postJson('/api/auth/login', ['phone' => '+97336000013', 'password' => 'password'])
        ->assertStatus(422)
        ->assertJsonPath('errors.phone.0', __('api.auth.use_otp'));
});

it('blocks inactive users', function () {
    $user = User::factory()->inactive()->create(['phone' => '+97336000014']);
    $user->assignRole('teacher');

    $this->postJson('/api/auth/login', ['phone' => '+97336000014', 'password' => 'password'])->assertStatus(422);
});

it('returns the current user and revokes the token on logout', function () {
    $user = actingAsRole('teacher');

    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    $this->putJson('/api/auth/locale', ['locale' => 'en'])->assertOk()->assertJsonPath('data.locale', 'en');
});

it('answers in English when Accept-Language is en', function () {
    $this->postJson('/api/auth/login', [], ['Accept-Language' => 'en-US'])
        ->assertStatus(422)
        ->assertJsonPath('errors.phone.0', 'The phone field is required.');

    $this->postJson('/api/auth/login', [], ['Accept-Language' => 'ar'])
        ->assertStatus(422)
        ->assertJsonPath('errors.phone.0', fn ($m) => str_contains($m, 'مطلوب') || str_contains($m, 'إلزامي'));
});
