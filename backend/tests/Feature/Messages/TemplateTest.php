<?php

use App\Models\MessageTemplate;

it('lists templates in both languages', function () {
    actingAsRole('supervisor');

    $this->getJson('/api/messages/templates')->assertOk()
        ->assertJsonFragment(['key' => 'otp'])
        ->assertJsonStructure(['data' => [['key', 'name_ar', 'name_en', 'body_ar', 'body_en', 'variables', 'is_active']]]);
});

it('updates a template and rejects unknown placeholders', function () {
    actingAsRole('supervisor');
    $t = MessageTemplate::where('key', 'absence')->first();

    $this->putJson("/api/messages/templates/{$t->id}", ['body_en' => 'Hi {name}, missed {assignment} on {date}.'])->assertOk();
    expect($t->fresh()->body_en)->toContain('missed {assignment}');

    $this->putJson("/api/messages/templates/{$t->id}", ['body_ar' => 'مرحبا {nope}'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body_ar');
});

it('previews a template with sample values', function () {
    actingAsRole('supervisor');
    $t = MessageTemplate::where('key', 'otp')->first();

    $body = $this->getJson("/api/messages/templates/{$t->id}/preview?locale=en")->assertOk()->json('body');
    expect($body)->toContain('123456')->not->toContain('{code}');
});

it('forbids teachers from editing templates', function () {
    actingAsRole('teacher');
    $t = MessageTemplate::where('key', 'otp')->first();

    $this->putJson("/api/messages/templates/{$t->id}", ['body_en' => 'x'])->assertForbidden();
});
