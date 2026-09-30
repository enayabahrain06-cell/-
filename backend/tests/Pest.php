<?php

use App\Models\User;
use Database\Seeders\AttendanceTemplateSeeder;
use Database\Seeders\EngagementTemplateSeeder;
use Database\Seeders\GalleryTemplateSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, MessageTemplateSeeder::class, EngagementTemplateSeeder::class, AttendanceTemplateSeeder::class, GalleryTemplateSeeder::class]);
    })
    ->in('Feature', 'Unit');

/** Create a user with the given role and authenticate as them (Sanctum). */
function actingAsRole(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);
    test()->actingAs($user, 'sanctum');

    return $user;
}
