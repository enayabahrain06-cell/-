<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\Locale;
use App\Enums\MemorizationLevel;
use App\Enums\RegistrationStatus;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationRequest extends Model
{
    use HasFactory, HasMedia;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'status' => RegistrationStatus::class,
            'memorization_level' => MemorizationLevel::class,
            'locale' => Locale::class,
            'birth_date' => 'date',
            'decided_at' => 'datetime',
            'age_at_start' => 'integer',
            'waitlist_position' => 'integer',
        ];
    }

    public static function nextRequestNo(): string
    {
        $prefix = 'R'.now()->format('ym');
        $last = static::where('request_no', 'like', "{$prefix}%")->orderByDesc('request_no')->value('request_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return sprintf('%s%04d', $prefix, $seq);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
