<?php

namespace App\Models;

use App\Enums\LotteryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lottery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => LotteryStatus::class,
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
