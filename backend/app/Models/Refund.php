<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'amount_fils' => 'integer', 'paid_at' => 'datetime'];
    }

    public static function nextNo(): string
    {
        $prefix = 'RF'.now()->format('ym');
        $last = static::where('refund_no', 'like', "{$prefix}%")->orderByDesc('refund_no')->value('refund_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return sprintf('%s%05d', $prefix, $seq);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
