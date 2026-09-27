<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Configurable badge rule (section 13). Awarded by the scheduled honor job; rows are reference data. */
class Badge extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rule_params' => 'array',
            'rule_value' => 'integer',
            'bonus_points' => 'integer',
            'repeatable_monthly' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function localizedName(string $locale): string
    {
        return $locale === 'en' ? $this->name_en : $this->name_ar;
    }

    public function awards(): HasMany
    {
        return $this->hasMany(StudentBadge::class);
    }
}
