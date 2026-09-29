<?php

namespace Ahl\Certificates\Support;

use Ahl\Certificates\Contracts\FileStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Files on a Laravel disk (certificates.storage), one path per owner and slot: certificates/{table}/{id}/{slot}. */
class DiskFileStore implements FileStore
{
    public function put(Model $owner, string $slot, string $contents, string $mime, string $filename): void
    {
        $this->disk()->put($this->path($owner, $slot), $contents);
    }

    public function get(Model $owner, string $slot): ?array
    {
        $path = $this->path($owner, $slot);
        if (! $this->disk()->exists($path)) {
            return null;
        }

        return ['contents' => (string) $this->disk()->get($path), 'mime' => $this->disk()->mimeType($path) ?: 'application/octet-stream'];
    }

    public function exists(Model $owner, string $slot): bool
    {
        return $this->disk()->exists($this->path($owner, $slot));
    }

    public function delete(Model $owner, string $slot): void
    {
        $this->disk()->delete($this->path($owner, $slot));
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('certificates.storage.disk', 'local'));
    }

    private function path(Model $owner, string $slot): string
    {
        return trim((string) config('certificates.storage.directory', 'certificates'), '/').'/'.$owner->getTable().'/'.$owner->getKey().'/'.Str::slug($slot, '_');
    }
}
