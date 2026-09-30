<?php

namespace App\Services\Gallery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

/**
 * Short videos are an add-on: they need ffmpeg and ffprobe on the server (duration check and poster frame). When
 * they are missing, or GALLERY_VIDEO=off, the gallery works with photos only: the options endpoint reports
 * video_enabled = false, the upload screen hides videos, and the API refuses video files.
 */
class VideoSupport
{
    public const ACCEPTED_MIMES = ['video/mp4', 'video/quicktime', 'video/webm'];

    public function enabled(): bool
    {
        if (config('ahl.gallery.video') === 'off') {
            return false;
        }

        return Cache::remember('gallery.ffmpeg_available', now()->addHour(), fn () => $this->runs(config('ahl.gallery.ffmpeg'))
            && $this->runs(config('ahl.gallery.ffprobe')));
    }

    public function isVideo(string $mime): bool
    {
        return in_array(strtolower($mime), self::ACCEPTED_MIMES, true);
    }

    /**
     * Check the file and make its poster frame.
     *
     * @return array{duration: int, poster: string} poster = path of a temporary JPEG (the caller deletes it)
     */
    public function inspect(string $path, int $size): array
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['file' => __('gallery.errors.video_disabled')]);
        }
        $maxMb = (int) config('ahl.gallery.video_max_mb', 50);
        if ($size > $maxMb * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => __('gallery.errors.too_large', ['max' => $maxMb])]);
        }

        $probe = Process::timeout(30)->run([config('ahl.gallery.ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path]);
        $duration = (float) trim($probe->output());
        if (! $probe->successful() || $duration <= 0) {
            throw ValidationException::withMessages(['file' => __('gallery.errors.video_unreadable')]);
        }
        $maxSeconds = (int) config('ahl.gallery.video_max_seconds', 60);
        if ($duration > $maxSeconds + 0.5) {
            throw ValidationException::withMessages(['file' => __('gallery.errors.video_too_long', ['max' => $maxSeconds])]);
        }

        $base = tempnam(sys_get_temp_dir(), 'poster');
        @unlink($base);
        $poster = $base.'.jpg';
        $at = $duration > 1 ? '1' : '0';
        $frame = Process::timeout(60)->run([config('ahl.gallery.ffmpeg'), '-y', '-v', 'error', '-ss', $at, '-i', $path, '-frames:v', '1', '-q:v', '3', $poster]);
        if (! $frame->successful() || ! is_file($poster) || filesize($poster) === 0) {
            @unlink($poster);
            throw ValidationException::withMessages(['file' => __('gallery.errors.video_unreadable')]);
        }

        return ['duration' => (int) ceil($duration), 'poster' => $poster];
    }

    private function runs(?string $binary): bool
    {
        if (! $binary) {
            return false;
        }
        try {
            return Process::timeout(10)->run([$binary, '-version'])->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
