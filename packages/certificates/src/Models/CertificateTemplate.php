<?php

namespace Ahl\Certificates\Models;

use Ahl\Certificates\Certificates;
use Illuminate\Database\Eloquent\Model;

/**
 * One editable template per certificate type. Title and body are per-locale maps ({"en": "…", "ar": "…"}).
 * Signature images live in the FileStore (slots signature_1 / signature_2).
 */
class CertificateTemplate extends Model
{
    public const SIGNATURE_SLOTS = [1, 2];

    protected $table = 'certificate_templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['title' => 'array', 'body' => 'array', 'show_photo' => 'boolean'];
    }

    /** The template for a type, created from the default texts on first use. */
    public static function forType(string $type): static
    {
        return static::query()->firstOrCreate(['type' => $type], [
            'title' => static::defaults($type, 'title'),
            'body' => static::defaults($type, 'body'),
            'ornament_level' => 'full',
            'show_photo' => false,
        ]);
    }

    /** @return array<string, string> default text per locale */
    public static function defaults(string $type, string $field): array
    {
        $prefix = config('certificates.defaults_translation', 'certificates::certificates.defaults');

        return collect(Certificates::locales())->mapWithKeys(function ($locale) use ($prefix, $type, $field) {
            $key = "{$prefix}.{$type}.{$field}";
            $text = __($key, [], $locale);
            if ($text === $key) {
                $text = __("{$prefix}.default.{$field}", [], $locale);
            }

            return [$locale => is_string($text) && ! str_starts_with($text, $prefix) ? $text : ''];
        })->all();
    }

    public function title(string $locale): string
    {
        return $this->text('title', $locale);
    }

    public function body(string $locale): string
    {
        return $this->text('body', $locale);
    }

    private function text(string $field, string $locale): string
    {
        $map = (array) ($this->{$field} ?? []);

        return (string) ($map[$locale] ?? $map[config('certificates.fallback_locale', 'en')] ?? collect($map)->first(fn ($v) => $v !== null && $v !== '') ?? '');
    }
}
