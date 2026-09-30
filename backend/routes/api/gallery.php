<?php

use App\Http\Controllers\Api\Gallery\GalleryController;
use App\Http\Controllers\Api\Gallery\GalleryPhotoController;
use App\Http\Controllers\Api\Portal\FamilyGalleryController;
use Illuminate\Support\Facades\Route;

// معرض الصور (Phase 8), inside the auth:sanctum group. The file route is in public/gallery.php (own limiter, still
// signed-in only).
Route::prefix('gallery')->group(function () {
    Route::get('options', [GalleryController::class, 'options']);
    Route::get('albums', [GalleryController::class, 'index']);
    Route::post('albums', [GalleryController::class, 'store']);
    Route::get('albums/{album}', [GalleryController::class, 'show']);
    Route::put('albums/{album}', [GalleryController::class, 'update']);
    Route::delete('albums/{album}', [GalleryController::class, 'destroy']);
    Route::put('albums/{album}/sharing', [GalleryController::class, 'share']);
    Route::post('albums/{album}/reorder', [GalleryController::class, 'reorder']);
    Route::get('albums/{album}/consent', [GalleryController::class, 'consent']);
    Route::post('albums/{album}/photos', [GalleryPhotoController::class, 'store']);
    Route::put('photos/{photo}', [GalleryPhotoController::class, 'update']);
    Route::delete('photos/{photo}', [GalleryPhotoController::class, 'destroy']);
});

// الصور in the family portal: albums shared with the signed-in guardian's (or student's) family.
Route::get('me/gallery', [FamilyGalleryController::class, 'index']);
Route::get('me/gallery/{album}', [FamilyGalleryController::class, 'show']);
