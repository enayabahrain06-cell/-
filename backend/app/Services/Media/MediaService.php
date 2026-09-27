<?php

namespace App\Services\Media;

use App\Enums\MediaCollection;
use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Single storage pattern for every file (photos, receipts, exam sheets, recitation audio,
 * certificates, logo). Files live on the configured disk (MEDIA_DISK: local | s3); the
 * `media` table is the source of truth. Files are never public: serve them through
 * signed routes that check a policy first (see MediaController).
 */
class MediaService
{
    public function disk(): string
    {
        return config('ahl.media.disk', 'local');
    }

    /** Store an uploaded file as-is (no processing). */
    public function storeUpload(Model $model, MediaCollection $collection, UploadedFile $file, bool $replace = true): Media
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $this->pathFor($model, $collection, $ext);

        Storage::disk($this->disk())->putFileAs(dirname($path), $file, basename($path));

        return $this->record($model, $collection, $path, $file->getMimeType() ?: 'application/octet-stream', $file->getSize(), $file->getClientOriginalName(), $replace);
    }

    /** Store raw bytes (generated PDFs, processed images). */
    public function storeContents(Model $model, MediaCollection $collection, string $contents, string $ext, string $mime, ?string $originalName = null, bool $replace = true): Media
    {
        $path = $this->pathFor($model, $collection, $ext);
        Storage::disk($this->disk())->put($path, $contents);

        return $this->record($model, $collection, $path, $mime, strlen($contents), $originalName, $replace);
    }

    public function delete(Media $media): void
    {
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }

    public function deleteCollection(Model $model, MediaCollection $collection): void
    {
        Media::where('model_type', $model->getMorphClass())->where('model_id', $model->getKey())
            ->where('collection', $collection->value)->get()->each(fn (Media $m) => $this->delete($m));
    }

    /** Raw file contents (for streaming through an authenticated route). */
    public function contents(Media $media): ?string
    {
        return Storage::disk($media->disk)->get($media->path);
    }

    public function exists(Media $media): bool
    {
        return Storage::disk($media->disk)->exists($media->path);
    }

    private function pathFor(Model $model, MediaCollection $collection, string $ext): string
    {
        $type = Str::kebab(class_basename($model));

        return sprintf('media/%s/%d/%s-%s.%s', $type, $model->getKey(), $collection->value, Str::lower(Str::random(12)), $ext);
    }

    private function record(Model $model, MediaCollection $collection, string $path, string $mime, int $size, ?string $originalName, bool $replace): Media
    {
        if ($replace) {
            $this->deleteCollection($model, $collection);
        }

        return Media::create([
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
            'collection' => $collection,
            'disk' => $this->disk(),
            'path' => $path,
            'mime' => $mime,
            'size' => $size,
            'original_name' => $originalName ? Str::limit($originalName, 250, '') : null,
            'created_by' => auth()->id(),
        ]);
    }
}
