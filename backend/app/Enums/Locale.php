<?php

namespace App\Enums;

enum Locale: string
{
    use \App\Enums\Concerns\HasLabel;

    case Arabic = 'ar';
    case English = 'en';

    public function direction(): string
    {
        return $this === self::Arabic ? 'rtl' : 'ltr';
    }

    public static function fromHeader(?string $acceptLanguage): self
    {
        if ($acceptLanguage && str_starts_with(strtolower(trim($acceptLanguage)), 'en')) {
            return self::English;
        }

        return self::Arabic;
    }
}
