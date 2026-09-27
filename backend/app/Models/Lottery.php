<?php

namespace App\Models;

use App\Enums\LotteryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lottery extends Model
{
    protected $guarded = ['id'];

    /** A lottery runs inside one package, so it inherits that package gender. */
    protected static function booted(): void
    {
        static::saving(function (Lottery $lottery) {
            if ($lottery->package_id && ($lottery->isDirty('package_id') || ! $lottery->gender)) {
                $lottery->gender = Package::whereKey($lottery->package_id)->toBase()->value('gender');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => LotteryStatus::class,
            'gender' => \App\Enums\Gender::class,
            'balance_ages' => 'boolean', 'keep_siblings' => 'boolean', 'balance_levels' => 'boolean',
            'run_at' => 'datetime', 'approved_at' => 'datetime', 'run_count' => 'integer',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(LotteryTeacher::class);
    }

    public function pool(): HasMany
    {
        return $this->hasMany(LotteryStudent::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(LotteryResult::class);
    }
}
