<?php

namespace App\Services\Messaging;

use App\Models\MessageTemplate;

class TemplateRenderer
{
    public const VARIABLES = [
        'name', 'lesson', 'time', 'assignment', 'date', 'teacher', 'location', 'map_link',
        'request_no', 'score', 'balance', 'amount', 'invoice_no', 'code', 'authority', 'package', 'status', 'link',
        'guardian_name', 'next_date', 'absence_count', 'supervisor_phone',
    ];

    public function render(string $templateKey, array $vars, string $locale): string
    {
        $template = MessageTemplate::query()->where('key', $templateKey)->where('is_active', true)->first();

        $body = $template ? $template->bodyFor($locale) : ($vars['body'] ?? '');

        return $this->interpolate($body, $vars, $locale);
    }

    public function interpolate(string $body, array $vars, string $locale): string
    {
        $vars += ['authority' => $locale === 'en' ? config('ahl.authority.name_en') : config('ahl.authority.name_ar')];

        return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0];
        }, $body);
    }
}
