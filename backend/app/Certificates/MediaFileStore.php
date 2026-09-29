<?php

namespace App\Certificates;

use Ahl\Certificates\Contracts\FileStore;
use App\Enums\MediaCollection;
use App\Models\Media;
use App\Services\Media\MediaService;
use Illuminate\Database\Eloquent\Model;

/**
 * Package files in this system's media table: the approved PDF is the "certificate" collection,
 * signature_1 / signature_2 are the "signature" collection told apart by original_name.
 */
class MediaFileStore implements FileStore
{
    private const EXTENSIONS = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'];

    public function __construct(private MediaService $media) {}

    public function put(Model $owner, string $slot, string $contents, string $mime, string $filename): void
    {
        $ext = self::EXTENSIONS[$mime] ?? 'bin';
        if ($slot === 'pdf') {
            $this->media->storeContents($owner, MediaCollection::Certificate, $contents, $ext, $mime, $filename);

            return;
        }
        $this->delete($owner, $slot);
        $this->media->storeContents($owner, MediaCollection::Signature, $contents, $ext, $mime, $slot, false);
    }

    public function get(Model $owner, string $slot): ?array
    {
        $media = $this->find($owner, $slot);
        $bytes = $media ? $this->media->contents($media) : null;

        return $bytes ? ['contents' => $bytes, 'mime' => $media->mime] : null;
    }

    public function exists(Model $owner, string $slot): bool
    {
        return $this->find($owner, $slot) !== null;
    }

    public function delete(Model $owner, string $slot): void
    {
        if ($slot === 'pdf') {
            $this->media->deleteCollection($owner, MediaCollection::Certificate);

            return;
        }
        if ($media = $this->find($owner, $slot)) {
            $this->media->delete($media);
        }
    }

    private function find(Model $owner, string $slot): ?Media
    {
        $q = Media::where('model_type', $owner->getMorphClass())->where('model_id', $owner->getKey());

        return $slot === 'pdf'
            ? $q->where('collection', MediaCollection::Certificate->value)->latest('id')->first()
            : $q->where('collection', MediaCollection::Signature->value)->where('original_name', $slot)->latest('id')->first();
    }
}
