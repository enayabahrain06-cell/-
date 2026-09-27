<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'due_date' => \App\Casts\DateOnly::class,
            'amount_fils' => 'integer', 'paid_fils' => 'integer',
            'reminder_before_sent_at' => 'datetime', 'reminder_after_sent_at' => 'datetime',
        ];
    }

    public static function nextNo(): string
    {
        $prefix = 'INV'.now()->format('ym');
        $last = static::where('invoice_no', 'like', "{$prefix}%")->orderByDesc('invoice_no')->value('invoice_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return sprintf('%s%05d', $prefix, $seq);
    }

    public function outstandingFils(): int
    {
        return max(0, $this->amount_fils - $this->paid_fils);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }
}
