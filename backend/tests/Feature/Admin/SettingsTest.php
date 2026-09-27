<?php

use App\Models\AuditLog;
use App\Models\Media;
use App\Services\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function settingItem(array $payload, string $key): ?array
{
    return collect($payload['groups'])->flatMap(fn ($g) => $g['settings'])->firstWhere('key', $key);
}

it('lists grouped settings with input metadata for the Super Admin only', function () {
    actingAsRole('super_admin');
    $d = $this->getJson('/api/admin/settings')->assertOk()->json('data');

    expect(collect($d['groups'])->pluck('key')->all())->toContain('authority', 'locale', 'reminders', 'certificates', 'ui', 'media')
        ->and(settingItem($d, 'ui.ornament_level'))->toMatchArray(['type' => 'string', 'value' => 'full', 'editable' => true, 'options' => ['full', 'minimal', 'off']])
        ->and(settingItem($d, 'attendance.repeated_absence_count'))->toMatchArray(['type' => 'int', 'min' => 2, 'max' => 20])
        ->and(settingItem($d, 'reminders.weekly_report_day')['options'])->toContain('thu')
        ->and(settingItem($d, 'locale.timezone')['options'])->toContain('Asia/Bahrain')
        ->and(settingItem($d, 'locale.currency')['editable'])->toBeFalse()
        ->and($d['logo_url'])->toBeNull();

    foreach (['supervisor', 'teacher'] as $role) {
        actingAsRole($role, ['gender' => 'male', 'track' => 'male']);
        $this->getJson('/api/admin/settings')->assertForbidden();
        $this->putJson('/api/admin/settings', ['settings' => ['ui.ornament_level' => 'off']])->assertForbidden();
        $this->postJson('/api/admin/settings/logo', ['logo' => UploadedFile::fake()->image('l.png', 200, 200)])->assertForbidden();
    }
});

it('returns seeded keys the registry does not know read-only, and refuses to save them', function () {
    app(SettingsService::class)->set('messaging.some_future_key', 'x', 'messaging', 'string');

    actingAsRole('super_admin');
    $item = settingItem($this->getJson('/api/admin/settings')->assertOk()->json('data'), 'messaging.some_future_key');
    expect($item)->toMatchArray(['value' => 'x', 'editable' => false, 'known' => false]);

    $this->putJson('/api/admin/settings', ['settings' => ['messaging.some_future_key' => 'y']])
        ->assertUnprocessable()->assertJsonValidationErrors(['messaging.some_future_key']);
    $this->putJson('/api/admin/settings', ['settings' => ['nope.key' => 1]])->assertUnprocessable();
    $this->putJson('/api/admin/settings', ['settings' => ['locale.currency' => 'SAR']])->assertUnprocessable();
    $this->putJson('/api/admin/settings', ['settings' => ['authority.logo_media_id' => 5]])->assertUnprocessable();
    expect(setting('messaging.some_future_key'))->toBe('x');
});

it('validates each key by the registry', function () {
    actingAsRole('super_admin');

    $this->putJson('/api/admin/settings', ['settings' => [
        'ui.ornament_level' => 'loud',
        'attendance.repeated_absence_count' => 1,
        'locale.timezone' => 'Mars/Olympus',
        'locale.country_code' => '+973',
        'reminders.weekly_report_day' => 'someday',
        'progress.default_direction' => 'sideways',
        'registration.open' => 'maybe',
        'authority.name_ar' => '',
    ]])->assertUnprocessable()->assertJsonValidationErrors([
        'ui.ornament_level', 'attendance.repeated_absence_count', 'locale.timezone', 'locale.country_code',
        'reminders.weekly_report_day', 'progress.default_direction', 'registration.open', 'authority.name_ar',
    ]);
    // Messages name the field by its label, not the raw key.
    $msg = $this->putJson('/api/admin/settings', ['settings' => ['attendance.repeated_absence_count' => 1]])
        ->json('errors')['attendance.repeated_absence_count'][0];
    expect($msg)->toContain('عدد الغيابات')->not->toContain('settings.labels'); // user's saved locale (ar) wins over the header
    $this->putJson('/api/admin/settings', ['settings' => []])->assertUnprocessable();
    $this->putJson('/api/admin/settings', [])->assertUnprocessable();

    expect(setting('ui.ornament_level'))->toBe('full');
});

it('saves a partial update, visible through the service and the public endpoint, with one audit row', function () {
    actingAsRole('super_admin');

    $res = $this->putJson('/api/admin/settings', ['settings' => [
        'ui.ornament_level' => 'minimal',
        'registration.open' => false,
        'attendance.repeated_absence_count' => '4',
        'authority.name_en' => '  Sar Quran Centre  ',
        'locale.show_hijri' => true, // unchanged: not audited
    ]])->assertOk();

    expect($res->json('changed'))->toEqualCanonicalizing(['ui.ornament_level', 'registration.open', 'attendance.repeated_absence_count', 'authority.name_en'])
        ->and(setting('attendance.repeated_absence_count'))->toBe(4)
        ->and(setting('registration.open'))->toBeFalse()
        ->and(setting('authority.name_en'))->toBe('Sar Quran Centre');

    $this->getJson('/api/public/settings')->assertOk()
        ->assertJsonPath('ornament_level', 'minimal')
        ->assertJsonPath('registration_open', false)
        ->assertJsonPath('authority.name_en', 'Sar Quran Centre');

    $audit = AuditLog::where('action', 'settings.updated')->sole();
    expect($audit->old_values)->toMatchArray(['ui.ornament_level' => 'full', 'registration.open' => true, 'attendance.repeated_absence_count' => 3])
        ->and($audit->new_values)->toMatchArray(['ui.ornament_level' => 'minimal', 'registration.open' => false, 'attendance.repeated_absence_count' => 4])
        ->and($audit->new_values)->not->toHaveKey('locale.show_hijri');

    // Saving the same values again changes nothing and writes no audit row.
    $this->putJson('/api/admin/settings', ['settings' => ['ui.ornament_level' => 'minimal']])->assertOk()->assertJsonPath('changed', []);
    expect(AuditLog::where('action', 'settings.updated')->count())->toBe(1);
});

it('uploads, serves publicly, replaces and removes the logo', function () {
    Storage::fake(config('ahl.media.disk', 'local'));
    actingAsRole('super_admin');

    $this->postJson('/api/admin/settings/logo', ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()->assertJsonValidationErrors(['logo']);

    $first = $this->postJson('/api/admin/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 300)])->assertOk()->json('data');
    $firstId = setting('authority.logo_media_id');
    expect($first['logo_url'])->toContain('/api/public/logo')->and($firstId)->toBeGreaterThan(0);

    $this->postJson('/api/admin/settings/logo', ['logo' => UploadedFile::fake()->image('logo2.png', 300, 300)])->assertOk();
    $secondId = setting('authority.logo_media_id');
    expect($secondId)->not->toBe($firstId)
        ->and(Media::where('collection', 'logo')->count())->toBe(1); // replaced, not piled up

    // Public: no token needed.
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->get('/api/public/logo')->assertOk()->assertHeader('Content-Type', 'image/png');

    actingAsRole('super_admin');
    $this->deleteJson('/api/admin/settings/logo')->assertOk()->assertJsonPath('data.logo_url', null);
    expect(Media::where('collection', 'logo')->count())->toBe(0)
        ->and((int) setting('authority.logo_media_id'))->toBe(0)
        ->and(AuditLog::whereIn('action', ['settings.logo_updated', 'settings.logo_removed'])->count())->toBe(3);
    $this->get('/api/public/logo')->assertNotFound();
});

it('saves and validates the honor board settings', function () {
    actingAsRole('super_admin');
    $this->putJson('/api/admin/settings', ['settings' => ['honor.weight_attendance' => 50, 'honor.full_attendance_min_sessions' => 6, 'honor.display_key' => 'sar-tv_1']])->assertOk();
    expect(setting('honor.weight_attendance'))->toBe(50)
        ->and(setting('honor.full_attendance_min_sessions'))->toBe(6)
        ->and(setting('honor.display_key'))->toBe('sar-tv_1');

    $this->putJson('/api/admin/settings', ['settings' => ['honor.weight_evaluation' => 101]])->assertUnprocessable();
    $this->putJson('/api/admin/settings', ['settings' => ['honor.full_attendance_min_sessions' => 0]])->assertUnprocessable();
    $this->putJson('/api/admin/settings', ['settings' => ['honor.display_key' => 'bad key!']])->assertUnprocessable();

    // Empty key turns the TV display off.
    $this->putJson('/api/admin/settings', ['settings' => ['honor.display_key' => '']])->assertOk();
    expect(setting('honor.display_key'))->toBeIn(['', null]);
});
