<?php

namespace App\Services\Gallery;

use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Turns an uploaded gallery photo into two WebP files: the viewer image (at most 2048 px on the long side) and a
 * 480 px square thumbnail for grids. Re-encoding drops every EXIF field, including the GPS position; the original is
 * never stored. Same GD limitation as PhotoProcessor: HEIC/HEIF is refused with a message (phones send JPEG when the
 * photo is picked in the browser).
 */
class GalleryImageProcessor
{
    public const ACCEPTED_MIMES = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    /** @return array{image: string, thumb: string, width: int, height: int} */
    public function process(string $path, string $mime, int $size): array
    {
        if (! in_array(strtolower($mime), self::ACCEPTED_MIMES, true)) {
            throw ValidationException::withMessages(['file' => __('gallery.errors.type')]);
        }
        $maxMb = (int) config('ahl.gallery.max_upload_mb', 15);
        if ($size > $maxMb * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => __('gallery.errors.too_large', ['max' => $maxMb])]);
        }

        return $this->encode($path, $mime);
    }

    /** Also used for a video's poster frame (already a trusted local JPEG). */
    public function encode(string $path, string $mime = 'image/jpeg'): array
    {
        // A 48 MP phone photo needs about 200 MB decoded in GD.
        @ini_set('memory_limit', '512M');

        try {
            $image = (new ImageManager(new GdDriver))->decodePath($path);
        } catch (\Throwable) {
            $key = str_starts_with(strtolower($mime), 'image/hei') ? 'media.heic_unsupported' : 'media.undecodable';
            throw ValidationException::withMessages(['file' => __($key)]);
        }

        $image->orient();
        $px = (int) config('ahl.gallery.image_px', 2048);
        $thumbPx = (int) config('ahl.gallery.thumb_px', 480);

        $large = $image->scaleDown($px, $px);
        $width = $large->width();
        $height = $large->height();
        $bytes = (string) $large->encode(new WebpEncoder(quality: 82));
        $thumb = (string) $large->cover($thumbPx, $thumbPx)->encode(new WebpEncoder(quality: 75));

        return ['image' => $bytes, 'thumb' => $thumb, 'width' => $width, 'height' => $height];
    }
}
