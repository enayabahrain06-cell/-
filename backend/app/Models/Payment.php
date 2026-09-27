<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasMedia;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'amount_fils' => 'integer', 'paid_at' => 'datetime', 'receipt_sent_at' => 'datetime'];
    }

    public static function nextReceiptNo(): string
    {
        $prefix = 'RC'.now()->format('ym');
        $last = static::where('receipt_no', 'like', "{$prefix}%")->orderByDesc('receipt_no')->value('receipt_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return sprintf('%s%05d', $prefix, $seq);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(WalletTransaction::class);
    }
}
