<?php

namespace Ahl\Certificates\Support;

use Ahl\Certificates\Certificates;
use Ahl\Certificates\Contracts\Host;
use Ahl\Certificates\Contracts\Recipient;
use Ahl\Certificates\Models\Certificate;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/** Sensible defaults: settings from config, every certificate visible to viewers, recipients searched by name. */
class DefaultHost implements Host
{
    public function setting(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function resolveRecipients(string $class, array $ids): Collection
    {
        return $class::query()->whereKey($ids)->get();
    }

    public function scopeVisible(Builder $query, Authenticatable $user): void {}

    public function searchRecipients(Builder $query, string $like): void
    {
        $classes = array_values(config('certificates.recipients', []));
        $column = config('certificates.recipient_search_column', 'name');
        if ($classes && $column) {
            $query->orWhereHasMorph('recipient', $classes, fn (Builder $q) => $q->where($column, 'like', $like));
        }
    }

    public function presentRecipient(Model $recipient): array
    {
        return [
            'id' => $recipient->getKey(),
            'type' => Certificates::recipientAlias($recipient),
            'name' => $recipient instanceof Recipient ? $recipient->certificateName() : (string) ($recipient->getAttribute('name') ?? $recipient->getKey()),
        ];
    }

    public function presentContext(Model $context): array
    {
        return [
            'id' => $context->getKey(),
            'type' => Certificates::contextAlias($context),
            'name' => (string) ($context->getAttribute('name') ?? $context->getAttribute('title') ?? $context->getKey()),
        ];
    }

    public function placeholders(Certificate $certificate, string $locale): array
    {
        return [];
    }

    public function placeholderKeys(): array
    {
        return [];
    }

    public function issuer(string $locale): string
    {
        $issuer = config('certificates.issuer', config('app.name'));

        return is_array($issuer) ? (string) ($issuer[$locale] ?? Arr::first($issuer) ?? '') : (string) $issuer;
    }

    public function secondaryDate(CarbonInterface $date, string $locale): ?string
    {
        return null;
    }

    public function displayTime(?CarbonInterface $time): ?CarbonInterface
    {
        return $time;
    }

    public function verifyUrl(Certificate $certificate): string
    {
        return str_replace('{token}', (string) $certificate->verify_token, (string) config('certificates.verify_url'));
    }

    public function viewData(array $data, ?Certificate $certificate, string $locale): array
    {
        return $data;
    }
}
