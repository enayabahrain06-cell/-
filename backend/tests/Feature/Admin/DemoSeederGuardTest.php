<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\EngagementDemoSeeder;

it('allows demo data only on local or testing installs with WhatsApp in log mode', function () {
    config(['whatsapp.provider' => 'log']);
    expect(DemoSeeder::refusal())->toBeNull(); // phpunit runs with APP_ENV=testing

    foreach (['production', 'staging'] as $env) {
        app()->detectEnvironment(fn () => $env);
        expect(DemoSeeder::refusal())->toContain('APP_ENV');
    }

    app()->detectEnvironment(fn () => 'local');
    config(['whatsapp.provider' => 'openwa']);
    expect(DemoSeeder::refusal())->toContain('WHATSAPP_PROVIDER');
});

it('creates no demo accounts when it refuses', function () {
    app()->detectEnvironment(fn () => 'staging');

    (new DemoSeeder)->run();
    (new EngagementDemoSeeder)->run();

    expect(User::where('phone', '+97336000001')->exists())->toBeFalse();
});
