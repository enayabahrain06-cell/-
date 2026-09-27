<?php

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

trait HasLabel
{
    /** Translated label from lang/{locale}/enums.php, keyed by snake_case enum name. */
    public function label(?string $locale = null): string
    {
        $group = Str::snake(class_basename(static::class));

        return __("enums.{$group}.{$this->value}", [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Laravel validation rule string: in:a,b,c */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::values());
    }

    public static function options(?string $locale = null): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label($locale)], self::cases());
    }
}
