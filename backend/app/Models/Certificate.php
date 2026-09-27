<?php

namespace App\Models;

use App\Enums\CertificateType;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    use HasFactory, HasMedia;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => CertificateType::class, 'issued_on' => 'date', 'sent_at' => 'datetime'];
    }

    public static function nextNo(): string
    {
        $prefix = 'C'.now()->format('y');
        $last = static::where('certificate_no', 'like', "{$prefix}%")->orderByDesc('certificate_no')->value('certificate_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return sprintf('%s%05d', $prefix, $seq);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
