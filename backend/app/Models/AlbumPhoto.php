<?php

namespace App\Models;

use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One photo or short video of an album. Its files are media rows: gallery_image (at most 2048 px WebP) and
 * gallery_thumb (480 px square WebP) for every item, plus gallery_video for a video (the thumb is its poster frame).
 */
class AlbumPhoto extends Model
{
    use HasMedia;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'width' => 'integer', 'height' => 'integer', 'duration_seconds' => 'integer'];
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
