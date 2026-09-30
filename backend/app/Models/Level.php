<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A study level (المستوى): a layer above circles. One level holds several circles; a circle may have none. */
class Level extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort')->orderBy('id');
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' ? $this->name_en : $this->name_ar;
    }
}
