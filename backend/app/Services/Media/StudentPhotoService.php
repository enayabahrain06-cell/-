<?php

namespace App\Services\Media;

use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\RegistrationRequest;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudentPhotoService
{
    public function __construct(private MediaService $media, private PhotoProcessor $processor) {}

    /** Process and store the two variants for a student; caches the paths on the student row. */
    public function setPhoto(Student $student, UploadedFile|string $source): Student
    {
        $variants = $this->processor->process($source);

        DB::transaction(function () use ($student, $variants) {
            [$profile, $thumb] = $this->storeVariants($student, $variants);
            $student->forceFill(['photo_path' => $profile->path, 'photo_thumb_path' => $thumb->path])->save();
        });

        return $student->refresh();
    }

    public function removePhoto(Student $student): void
    {
        DB::transaction(function () use ($student) {
            $this->media->deleteCollection($student, MediaCollection::Photo);
            $this->media->deleteCollection($student, MediaCollection::PhotoThumb);
            $student->forceFill(['photo_path' => null, 'photo_thumb_path' => null])->save();
        });
    }

    /** Same processing for a registration request (photo attached before acceptance). */
    public function setRequestPhoto(RegistrationRequest $request, UploadedFile|string $source): void
    {
        $variants = $this->processor->process($source);
        $this->storeVariants($request, $variants);
    }

    public function removeRequestPhoto(RegistrationRequest $request): void
    {
        $this->media->deleteCollection($request, MediaCollection::Photo);
        $this->media->deleteCollection($request, MediaCollection::PhotoThumb);
    }

    /**
     * Copy the request's photo variants to the student on acceptance.
     * No-op when the request has no photo.
     */
    public function transferPhoto(RegistrationRequest $request, Student $student): void
    {
        $profile = $request->mediaIn(MediaCollection::Photo);
        $thumb = $request->mediaIn(MediaCollection::PhotoThumb);

        if (! $profile || ! $thumb) {
            return;
        }

        $profileBytes = $this->media->contents($profile);
        $thumbBytes = $this->media->contents($thumb);

        if ($profileBytes === null || $thumbBytes === null) {
            return;
        }

        DB::transaction(function () use ($student, $profileBytes, $thumbBytes) {
            [$p, $t] = $this->storeVariants($student, ['profile' => $profileBytes, 'thumb' => $thumbBytes]);
            $student->forceFill(['photo_path' => $p->path, 'photo_thumb_path' => $t->path])->save();
        });
    }

    /** @return array{0: Media, 1: Media} */
    private function storeVariants(Model $model, array $variants): array
    {
        $profile = $this->media->storeContents($model, MediaCollection::Photo, $variants['profile'], 'webp', 'image/webp', 'photo.webp');
        $thumb = $this->media->storeContents($model, MediaCollection::PhotoThumb, $variants['thumb'], 'webp', 'image/webp', 'photo_thumb.webp');

        return [$profile, $thumb];
    }

    public function contentsFor(Student $student, string $size): ?string
    {
        $path = $size === 'profile' ? $student->photo_path : $student->photo_thumb_path;
        if (! $path) {
            return null;
        }

        $disk = Storage::disk($this->media->disk());

        return $disk->exists($path) ? $disk->get($path) : null;
    }
}
