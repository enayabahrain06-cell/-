<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * معرض الصور: an album of one term and gender track. It may link to one class, level, competition or activity
 * (link_type + link_id); the linked record is read, never copied. See GalleryAccess for who sees and changes what.
 */
class Album extends Model
{
    public const VISIBILITIES = ['staff', 'linked', 'all_guardians'];

    /** link_type => model. An activity is a program or a trip (البرامج والرحلات). */
    public const LINKS = [
        'lesson' => Lesson::class,
        'level' => Level::class,
        'competition' => Competition::class,
        'activity' => Activity::class,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'album_date' => \App\Casts\DateOnly::class,
            'allow_download' => 'boolean',
            'shared_at' => 'datetime',
            'link_id' => 'integer',
            'cover_photo_id' => 'integer',
        ];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(AlbumPhoto::class)->orderBy('position')->orderBy('id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(AlbumPhoto::class, 'cover_photo_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The linked class, level, competition or activity, or null. */
    public function linked(): ?Model
    {
        $class = self::LINKS[$this->link_type] ?? null;

        return $class && $this->link_id ? $class::find($this->link_id) : null;
    }

    public function isShared(): bool
    {
        return $this->visibility !== 'staff';
    }

    /** The cover, or the first photo when none was chosen. */
    public function coverPhoto(): ?AlbumPhoto
    {
        return $this->cover ?? $this->photos()->first();
    }
}
