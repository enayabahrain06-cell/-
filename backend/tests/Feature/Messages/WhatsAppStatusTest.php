<?php

it('reports the log provider as connected and has no qr', function () {
    actingAsRole('super_admin');

    $this->getJson('/api/whatsapp/status')->assertOk()
        ->assertJsonPath('provider', 'log')
        ->assertJsonPath('connected', true);

    $this->getJson('/api/whatsapp/qr')->assertNoContent();
});

it('requires the whatsapp.status permission', function () {
    actingAsRole('teacher');
    $this->getJson('/api/whatsapp/status')->assertForbidden();
});
