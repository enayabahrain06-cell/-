<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A book handed to a student (متابعة الكتب); invoice_id when its price was charged. */
class BookDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['delivered_at' => \App\Casts\DateOnly::class, 'book_id' => 'integer', 'student_id' => 'integer'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }
}
