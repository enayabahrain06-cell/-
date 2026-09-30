<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student in a program or trip: registered, on the waiting list or cancelled; the fee and book invoices by id. */
class ActivityRegistration extends Model
{
    public const STATUSES = ['registered', 'waitlist', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'activity_id' => 'integer', 'student_id' => 'integer', 'fee_invoice_id' => 'integer', 'book_invoice_id' => 'integer'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function feeInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'fee_invoice_id');
    }

    public function bookInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'book_invoice_id');
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
