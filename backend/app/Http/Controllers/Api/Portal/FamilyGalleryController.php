<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Api\Gallery\GalleryController;
use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\AlbumPhoto;
use App\Services\Gallery\GalleryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Student portal
 *
 * الصور in the family portal: only albums shared with this family (GalleryAccess). Staff-only fields (visibility,
 * who uploaded) are left out by the presenters.
 */
class FamilyGalleryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $albums = GalleryAccess::familyAlbums(GalleryAccess::family($user));
        $albums->load('cover')->loadCount('photos');

        return response()->json(['data' => $albums->map(fn (Album $a) => GalleryController::present($a, $user))->values()]);
    }

    public function show(Request $request, Album $album): JsonResponse
    {
        $user = $request->user();
        abort_unless(GalleryAccess::familyAllowed($user, $album), 404);
        $album->load(['cover', 'photos'])->loadCount('photos');

        return response()->json(['data' => GalleryController::present($album, $user) + [
            'photos' => $album->photos->map(fn (AlbumPhoto $p) => GalleryController::photo($p, $user)),
        ]]);
    }
}
