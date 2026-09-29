<?php

namespace Ahl\Certificates\Contracts;

use Ahl\Certificates\Models\Certificate;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything the package needs from the system it runs in. Extend Support\DefaultHost and override what differs.
 */
interface Host
{
    /** Behaviour switches: require_approval, notify_on_approve, link_minutes. $default comes from config. */
    public function setting(string $key, mixed $default = null): mixed;

    /**
     * Recipients for POST /certificates.
     *
     * @param  class-string<Model>  $class
     * @param  list<int|string>  $ids
     */
    public function resolveRecipients(string $class, array $ids): Collection;

    /** Limit the certificate list to what $user may see (for example their own branch or class). */
    public function scopeVisible(Builder $query, Authenticatable $user): void;

    /** Add recipient conditions to the search box, inside an OR group ($like is "%term%"). */
    public function searchRecipients(Builder $query, string $like): void;

    /** Recipient card in API responses. Must include id, type and name. */
    public function presentRecipient(Model $recipient): array;

    /** Context card in API responses (id, type, name). */
    public function presentContext(Model $context): array;

    /**
     * Extra body placeholders beyond {name}, {achievement}, {grade}, {context}, {date}: token => value.
     *
     * @return array<string, string>
     */
    public function placeholders(Certificate $certificate, string $locale): array;

    /** @return list<string> the extra placeholder tokens, for the template editor */
    public function placeholderKeys(): array;

    /** Issuer printed on the certificate and the verification page. */
    public function issuer(string $locale): string;

    /** A second calendar under the date (e.g. Hijri), or null. */
    public function secondaryDate(CarbonInterface $date, string $locale): ?string;

    /** Timestamps in API responses (convert to the display time zone). */
    public function displayTime(?CarbonInterface $time): ?CarbonInterface;

    /** The public verification page (the QR target). */
    public function verifyUrl(Certificate $certificate): string;

    /** Last chance to add or change PDF view data. */
    public function viewData(array $data, ?Certificate $certificate, string $locale): array;
}
