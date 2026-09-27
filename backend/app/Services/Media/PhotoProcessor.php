<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Turns an uploaded student photo into two square WebP variants (512 px profile, 96 px thumbnail).
 * The original is never stored.
 *
 * Accepted input: JPG, PNG, HEIC/HEIF, up to 5 MB (config ahl.media.photo).
 * Limitation: the GD driver cannot decode HEIC/HEIF. When such a file arrives it is accepted by
 * validation but rejected at decode time with a localized message (media.heic_unsupported).
 * Switching IMAGE_DRIVER to the Imagick driver (with libheif) lifts this limitation.
 */
class PhotoProcessor
{
    public const ACCEPTED_MIMES = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];

    /** @return array{profile: string, thumb: string} WebP bytes */
    public function process(UploadedFile|string $source): array
    {
        [$path, $mime, $size] = $source instanceof UploadedFile
            ? [$source->getRealPath(), (string) $source->getMimeType(), (int) $source->getSize()]
            : [$source, (string) (mime_content_type($source) ?: ''), (int) filesize($source)];

        $this->validate($mime, $size);

        $manager = new ImageManager(new GdDriver);

        try {
            $image = $manager->decodePath($path);
        } catch (\Throwable $e) {
            $key = str_starts_with($mime, 'image/hei') ? 'media.heic_unsupported' : 'media.undecodable';
            throw ValidationException::withMessages(['photo' => __($key)]);
        }

        $image->orient();

        $profilePx = (int) config('ahl.media.photo.profile_px', 512);
        $thumbPx = (int) config('ahl.media.photo.thumb_px', 96);

        $profile = (string) $image->cover($profilePx, $profilePx)->encode(new WebpEncoder(quality: 85));
        $thumb = (string) $image->cover($thumbPx, $thumbPx)->encode(new WebpEncoder(quality: 80));

        return ['profile' => $profile, 'thumb' => $thumb];
    }

    private function validate(string $mime, int $size): void
    {
        if (! in_array(strtolower($mime), self::ACCEPTED_MIMES, true)) {
            throw ValidationException::withMessages(['photo' => __('media.invalid_type')]);
        }

        $maxKb = (int) config('ahl.media.photo.max_kb', 5120);
        if ($size > $maxKb * 1024) {
            throw ValidationException::withMessages(['photo' => __('media.too_large', ['max' => round($maxKb / 1024, 1)])]);
        }
    }
}
