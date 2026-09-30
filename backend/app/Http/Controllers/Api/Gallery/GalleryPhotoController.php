<?php

namespace App\Http\Controllers\Api\Gallery;

use App\Enums\MediaCollection;
use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\AlbumPhoto;
use App\Services\AuditLogger;
use App\Services\Gallery\GalleryAccess;
use App\Services\Gallery\GalleryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Photo gallery
 *
 * Photos of an album: one file per upload request (the screen sends several in parallel and shows each one's
 * progress), captions, deletion, and the only way to read a gallery file: file(), for signed-in users who may see
 * the album. There are no public or signed links.
 */
class GalleryPhotoController extends Controller
{
    public function store(Request $request, Album $album, GalleryService $gallery): JsonResponse
    {
        $user = $request->user();
        abort_unless(GalleryAccess::canUpload($user, $album), 403);
        $maxKb = max((int) config('ahl.gallery.max_upload_mb', 15), (int) config('ahl.gallery.video_max_mb', 50)) * 1024;
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb],
            'caption' => ['nullable', 'string', 'max:500'],
        ]);

        $photo = $gallery->upload($album, $data['file'], $data['caption'] ?? null, $user->id);
        $photo->setRelation('uploader', $user)->setRelation('album', $album);

        return response()->json(['data' => GalleryController::photo($photo, $user)], 201);
    }

    public function update(Request $request, AlbumPhoto $photo, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless(GalleryAccess::canEdit($user, $photo->album) || GalleryAccess::canDeletePhoto($user, $photo), 403);
        $data = $request->validate(['caption' => ['nullable', 'string', 'max:500']]);
        $old = $photo->only('caption');
        $photo->update($data);
        $audit->record('gallery.photo_updated', $photo, $old, $photo->only('caption'));

        return response()->json(['data' => GalleryController::photo($photo->load('uploader:id,name'), $user)]);
    }

    public function destroy(Request $request, AlbumPhoto $photo, GalleryService $gallery): JsonResponse
    {
        abort_unless(GalleryAccess::canDeletePhoto($request->user(), $photo), 403);
        $gallery->deletePhoto($photo);

        return response()->json(['message' => __('gallery.photo_deleted')]);
    }

    /**
     * One file of a photo: thumb (grid), image (viewer, and the video's poster) or video. ?download=1 sends it as an
     * attachment, only for users allowed to download from the album (audited); everyone else gets 403.
     */
    public function file(Request $request, AlbumPhoto $photo, string $variant, AuditLogger $audit): StreamedResponse
    {
        $user = $request->user();
        $album = $photo->album;
        abort_unless($album && GalleryAccess::canView($user, $album), 403);

        $collection = match ($variant) {
            'thumb' => MediaCollection::GalleryThumb,
            'image' => MediaCollection::GalleryImage,
            'video' => MediaCollection::GalleryVideo,
            default => abort(404),
        };
        $media = $photo->mediaIn($collection);
        abort_if($media === null || ! Storage::disk($media->disk)->exists($media->path), 404);

        $download = $request->boolean('download');
        if ($download) {
            abort_unless($variant !== 'thumb' && GalleryAccess::canDownload($user, $album), 403);
            $audit->record('gallery.photo_downloaded', $photo, [], ['album_id' => $album->id, 'variant' => $variant]);
        }

        $ext = pathinfo($media->path, PATHINFO_EXTENSION) ?: 'bin';
        $name = sprintf('album-%d-%d.%s', $album->id, $photo->id, $ext);

        return Storage::disk($media->disk)->response($media->path, $name, [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ], $download ? 'attachment' : 'inline');
    }
}
