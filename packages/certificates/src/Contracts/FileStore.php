<?php

namespace Ahl\Certificates\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * File storage for the package. Slots: "pdf" on a certificate, "signature_1" / "signature_2" on a template.
 */
interface FileStore
{
    public function put(Model $owner, string $slot, string $contents, string $mime, string $filename): void;

    /** @return array{contents:string, mime:string}|null */
    public function get(Model $owner, string $slot): ?array;

    public function exists(Model $owner, string $slot): bool;

    public function delete(Model $owner, string $slot): void;
}
