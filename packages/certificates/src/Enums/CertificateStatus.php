<?php

namespace Ahl\Certificates\Enums;

use Ahl\Certificates\Certificates;

/** Every certificate starts as a draft and becomes final once approved. Revoked certificates stay verifiable as revoked. */
enum CertificateStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Revoked = 'revoked';

    public function label(?string $locale = null): string
    {
        return Certificates::statusLabel($this, $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(?string $locale = null): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label($locale)], self::cases());
    }
}
