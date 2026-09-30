<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An academic term (e.g. "الفصل الدراسي الأول - 2026/2027"). Exactly one term is current at a time; the staff
 * term selector defaults to it. legacy_label keeps the free-text packages.term value a term was migrated from.
 */
class AcademicTerm extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => \App\Casts\DateOnly::class,
            'end_date' => \App\Casts\DateOnly::class,
            'is_current' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Newest first: current term, then by start date (undated last), then by id. */
    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderByDesc('is_current')
            ->orderByRaw('case when start_date is null then 1 else 0 end')
            ->orderByDesc('start_date')->orderBy('sort')->orderByDesc('id');
    }

    /** Display name of a term id, for resources that show "the term" (U7: the id is the only source). */
    public static function nameFor(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }
        // once(): the (tiny) table is read once per request; Laravel flushes it between requests and tests.
        $names = once(fn () => static::query()->get()->mapWithKeys(fn (self $t) => [$t->id => $t->name()])->all());

        return $names[$id] ?? static::find($id)?->name();
    }

    public static function current(): ?self
    {
        return static::where('is_current', true)->orderByDesc('id')->first();
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' ? $this->name_en : $this->name_ar;
    }

    /** Make this the only current term. */
    public function makeCurrent(): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () {
            static::whereKeyNot($this->id)->where('is_current', true)->update(['is_current' => false]);
            $this->forceFill(['is_current' => true])->save();
        });
    }
}
