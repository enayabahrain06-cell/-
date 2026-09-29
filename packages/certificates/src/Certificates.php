<?php

namespace Ahl\Certificates;

use Ahl\Certificates\Contracts\Host;
use Ahl\Certificates\Models\Certificate;
use Ahl\Certificates\Models\CertificateTemplate;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Entry point for the package configuration: model classes, the host adapter, and the labelled lists
 * (types, grades, sources, recipients, contexts) from config/certificates.php.
 */
class Certificates
{
    /** @return class-string<Certificate> */
    public static function model(): string
    {
        return config('certificates.models.certificate', Certificate::class);
    }

    /** @return class-string<CertificateTemplate> */
    public static function templateModel(): string
    {
        return config('certificates.models.template', CertificateTemplate::class);
    }

    public static function host(): Host
    {
        return app(Host::class);
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        return static::host()->setting($key, config("certificates.{$key}", $default));
    }

    /** @return list<string> */
    public static function locales(): array
    {
        return array_values(config('certificates.locales', ['en']));
    }

    /** A supported locale for $locale (the fallback when it is not one of the template languages). */
    public static function locale(?string $locale): string
    {
        $locales = static::locales();

        return in_array($locale, $locales, true) ? $locale : config('certificates.fallback_locale', $locales[0] ?? 'en');
    }

    /** @return list<string> keys of a labelled list: types, grades, sources */
    public static function keys(string $group): array
    {
        return array_map('strval', array_keys(config("certificates.{$group}", [])));
    }

    public static function has(string $group, ?string $key): bool
    {
        return $key !== null && array_key_exists($key, config("certificates.{$group}", []));
    }

    /** Label of a key in a list. Config values may be a translation key, a string, or a per-locale array. */
    public static function label(string $group, ?string $key, ?string $locale = null): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        $locale ??= app()->getLocale();
        $value = config("certificates.{$group}.{$key}");

        if (is_array($value)) {
            return $value[$locale] ?? Arr::first($value) ?? Str::headline($key);
        }
        if (is_string($value) && $value !== '') {
            $translated = __($value, [], $locale);

            return is_string($translated) ? $translated : Str::headline($key);
        }

        return Str::headline($key);
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(string $group, ?string $locale = null): array
    {
        return array_map(fn ($k) => ['value' => $k, 'label' => static::label($group, $k, $locale)], static::keys($group));
    }

    /** Status labels (package translations, overridable in lang/vendor/certificates). */
    public static function statusLabel(Enums\CertificateStatus $status, ?string $locale = null): string
    {
        return __("certificates::certificates.status.{$status->value}", [], $locale);
    }

    /** Plain string value of an enum or string. */
    public static function value(BackedEnum|string|null $v): ?string
    {
        return $v instanceof BackedEnum ? (string) $v->value : $v;
    }

    /** @return class-string<Model>|null */
    public static function recipientClass(?string $alias = null): ?string
    {
        $map = config('certificates.recipients', []);

        return $alias === null ? (Arr::first($map) ?: null) : ($map[$alias] ?? null);
    }

    public static function recipientAlias(Model|string $model): string
    {
        $class = is_string($model) ? $model : $model::class;
        $alias = array_search($class, config('certificates.recipients', []), true);

        return $alias !== false ? (string) $alias : Str::snake(class_basename($class));
    }

    /** @return class-string<Model>|null */
    public static function contextClass(?string $alias): ?string
    {
        return $alias === null ? null : (config('certificates.contexts', [])[$alias] ?? null);
    }

    public static function contextAlias(Model|string $model): string
    {
        $class = is_string($model) ? $model : $model::class;
        $alias = array_search($class, config('certificates.contexts', []), true);

        return $alias !== false ? (string) $alias : Str::snake(class_basename($class));
    }

    /** Morph class stored in recipient_type / context_type for a configured model class. */
    public static function morphClass(string $class): string
    {
        return (new $class)->getMorphClass();
    }
}
