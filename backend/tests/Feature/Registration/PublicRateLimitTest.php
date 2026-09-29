<?php

use App\Models\Package;

it('keeps page loads from using up the registration submit limit, which still applies on its own', function () {
    Package::factory()->create(['min_age' => 7, 'max_age' => 12, 'gender' => 'male', 'start_date' => now()->addDays(30)->toDateString()]);

    // Changing gender or birth date reloads the package list; that must not block sending the form.
    foreach (range(1, 12) as $i) {
        $this->getJson('/api/public/packages?gender=male')->assertOk();
    }
    $this->postJson('/api/public/registrations', [])->assertStatus(422);

    foreach (range(2, 10) as $i) {
        $this->postJson('/api/public/registrations', [])->assertStatus(422);
    }
    $this->postJson('/api/public/registrations', [])->assertStatus(429);
});
