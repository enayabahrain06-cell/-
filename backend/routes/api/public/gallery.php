<?php

use App\Http\Controllers\Api\Gallery\GalleryPhotoController;
use Illuminate\Support\Facades\Route;

// Gallery files. Not public: signed-in users only, checked against the album (GalleryAccess). Declared here only so
// thumbnails use their own limiter instead of the 120-a-minute api one.
Route::get('gallery/photos/{photo}/{variant}', [GalleryPhotoController::class, 'file'])
    ->whereIn('variant', ['thumb', 'image', 'video'])
    ->middleware(['auth:sanctum', 'active', 'throttle:gallery-files'])
    ->name('gallery.file');
