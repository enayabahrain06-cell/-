<?php

namespace App\Http\Controllers\Api\Media;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Student;
use App\Services\Media\MediaService;
use App\Services\Media\StudentPhotoService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * @group Media
 */
class MediaController extends Controller
{
    /**
     * Student photo by temporary signed URL (10 minutes). The signature is minted only after
     * the 'view-photo' gate passed (see StudentPhotoController::url and list resources).
     */
    public function studentPhoto(Student $student, string $size, StudentPhotoService $photos): Response
    {
        abort_unless(in_array($size, ['profile', 'thumb'], true), 404);

        $bytes = $photos->contentsFor($student, $size);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => strlen($bytes),
            'Cache-Control' => 'private, max-age=600',
            'Content-Disposition' => 'inline; filename="'.$student->student_no.'-'.$size.'.webp"',
        ]);
    }

    /** Stream any media file after a collection-based policy check. */
    public function show(Media $media, MediaService $service): Response
    {
        Gate::authorize('view-media', $media);

        $bytes = $service->contents($media);
        abort_if($bytes === null, 404);

        $collection = $media->collection?->value ?? 'file';
        $ext = pathinfo($media->path, PATHINFO_EXTENSION) ?: 'bin';
        $filename = $media->original_name ?: "{$collection}.{$ext}";

        return response($bytes, 200, [
            'Content-Type' => $media->mime,
            'Content-Length' => strlen($bytes),
            'Cache-Control' => 'private, max-age=0, no-cache',
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"; filename*=UTF-8\'\''.rawurlencode($filename),
        ]);
    }
}
