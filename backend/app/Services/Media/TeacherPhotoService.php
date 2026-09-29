<?php

namespace App\Services\Media;

use App\Enums\MediaCollection;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Track;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Teacher photos: the same 512 px + 96 px WebP variants as student photos, stored as media on the
 * teacher record (created on first upload) and served through short-lived signed URLs.
 */
class TeacherPhotoService
{
    public function __construct(private MediaService $media, private PhotoProcessor $processor) {}

    public function setPhoto(User $teacher, UploadedFile|string $source): void
    {
        $variants = $this->processor->process($source);
        $record = $this->record($teacher);

        DB::transaction(function () use ($record, $variants) {
            $this->media->storeContents($record, MediaCollection::Photo, $variants['profile'], 'webp', 'image/webp', 'photo.webp');
            $this->media->storeContents($record, MediaCollection::PhotoThumb, $variants['thumb'], 'webp', 'image/webp', 'photo_thumb.webp');
        });
    }

    public function removePhoto(User $teacher): void
    {
        if (! $record = $teacher->teacher) {
            return;
        }
        DB::transaction(function () use ($record) {
            $this->media->deleteCollection($record, MediaCollection::Photo);
            $this->media->deleteCollection($record, MediaCollection::PhotoThumb);
        });
    }

    /** Raw WebP bytes for the signed route, or null. */
    public function contentsFor(User $teacher, string $size): ?string
    {
        $media = $teacher->teacher?->mediaIn($size === 'profile' ? MediaCollection::Photo : MediaCollection::PhotoThumb);

        return $media && $this->media->exists($media) ? $this->media->contents($media) : null;
    }

    /** @return array{profile: string|null, thumb: string|null} 10-minute signed URLs, or nulls without a photo. */
    public function urls(User $teacher): array
    {
        $record = $teacher->teacher;
        $url = fn (MediaCollection $c, string $size) => $record?->mediaIn($c)
            ? URL::temporarySignedRoute('media.teacher-photo', now()->addMinutes((int) config('ahl.media.signed_url_minutes', 10)), ['teacher' => $teacher->id, 'size' => $size])
            : null;

        return ['profile' => $url(MediaCollection::Photo, 'profile'), 'thumb' => $url(MediaCollection::PhotoThumb, 'thumb')];
    }

    private function record(User $teacher): Teacher
    {
        // Same defaults as TeacherController::update for a teacher account without a record yet.
        return Teacher::firstOrCreate(['user_id' => $teacher->id], [
            'gender' => Track::staffGender($teacher)?->value ?? $teacher->gender ?? 'male',
            'is_active' => true,
        ]);
    }
}
