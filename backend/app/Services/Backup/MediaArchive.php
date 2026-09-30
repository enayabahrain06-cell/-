<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * One backup zip for the database and every uploaded file (db:backup --with-files, db:restore file.zip).
 *
 * Every upload lives under the media disk's `media/` folder: gallery photos and videos (media/album-photo/…),
 * student and teacher photos, receipts, exam sheets, recitations, certificates, the logo and signatures. On the
 * default local disk that is storage/app/private/media. The zip holds:
 *
 *   manifest.json          what, when, which driver, how many files
 *   database/<dump>        the db:backup output (.sql or .sqlite)
 *   files/media/…          the media folder, same layout
 */
class MediaArchive
{
    /** Absolute path of the media folder, or null when media is not on a local disk (S3: back up the bucket). */
    public function mediaRoot(): ?string
    {
        $disk = config('ahl.media.disk', 'local');
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return null;
        }

        return Storage::disk($disk)->path('media');
    }

    /** @return array{zip: string, files: int, bytes: int} */
    public function pack(string $databaseFile, string $zipPath, array $meta = []): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create {$zipPath}");
        }
        $zip->addFile($databaseFile, 'database/'.basename($databaseFile));

        $files = 0;
        $bytes = 0;
        $root = $this->mediaRoot();
        if ($root && is_dir($root)) {
            foreach (File::allFiles($root) as $f) {
                $rel = str_replace('\\', '/', $f->getRelativePathname());
                $zip->addFile($f->getPathname(), 'files/media/'.$rel);
                // Photos and videos are already compressed: store them, do not deflate again.
                $zip->setCompressionName('files/media/'.$rel, \ZipArchive::CM_STORE);
                $files++;
                $bytes += $f->getSize();
            }
        }

        $zip->addFromString('manifest.json', json_encode($meta + [
            'created_at' => now()->toIso8601String(),
            'database_file' => basename($databaseFile),
            'media_files' => $files,
            'media_bytes' => $bytes,
            'media_disk' => config('ahl.media.disk', 'local'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (! $zip->close()) {
            throw new \RuntimeException("Cannot write {$zipPath}");
        }

        return ['zip' => $zipPath, 'files' => $files, 'bytes' => $bytes];
    }

    /**
     * Extract the database dump to $tmpDir and return its path.
     */
    public function extractDatabase(string $zipPath, string $tmpDir): string
    {
        $zip = $this->open($zipPath);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'database/') && ! str_ends_with($name, '/')) {
                File::ensureDirectoryExists($tmpDir);
                $target = $tmpDir.'/'.basename($name);
                File::put($target, (string) $zip->getFromIndex($i));
                $zip->close();

                return $target;
            }
        }
        $zip->close();
        throw new \RuntimeException('The zip has no database/ entry: it was not made by db:backup --with-files.');
    }

    /**
     * Put the zip's files back into the media folder. Existing files with the same name are overwritten; files that
     * are not in the backup are left alone.
     *
     * @return int files restored
     */
    public function restoreFiles(string $zipPath): int
    {
        $root = $this->mediaRoot();
        if (! $root) {
            throw new \RuntimeException('Media is not on a local disk; restore the bucket separately.');
        }
        $zip = $this->open($zipPath);
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_starts_with($name, 'files/media/') || str_ends_with($name, '/')) {
                continue;
            }
            $rel = substr($name, strlen('files/media/'));
            // Never write outside the media folder.
            if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/') || preg_match('/^[A-Za-z]:/', $rel)) {
                continue;
            }
            $target = $root.'/'.$rel;
            File::ensureDirectoryExists(dirname($target));
            $in = $zip->getStream($name);
            $out = fopen($target, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $count++;
        }
        $zip->close();

        return $count;
    }

    private function open(string $zipPath): \ZipArchive
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException("Cannot open {$zipPath}");
        }

        return $zip;
    }
}
