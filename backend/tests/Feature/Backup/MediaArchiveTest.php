<?php

use App\Services\Backup\MediaArchive;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('ahl.media.disk', 'local');
    $this->dir = storage_path('framework/testing/backup-'.uniqid());
    File::ensureDirectoryExists($this->dir);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('packs the database dump with every media file and restores both', function () {
    Storage::disk('local')->put('media/album-photo/7/gallery_image-abc.webp', 'IMG');
    Storage::disk('local')->put('media/student/3/photo-xyz.webp', 'FACE');
    $dump = $this->dir.'/db.sqlite';
    File::put($dump, 'DB');

    $archive = app(MediaArchive::class);
    $out = $archive->pack($dump, $this->dir.'/backup.zip');
    expect($out['files'])->toBe(2)->and($out['bytes'])->toBe(7);

    $zip = new ZipArchive;
    $zip->open($out['zip']);
    expect($zip->locateName('database/db.sqlite'))->not->toBeFalse()
        ->and($zip->locateName('files/media/album-photo/7/gallery_image-abc.webp'))->not->toBeFalse()
        ->and(json_decode($zip->getFromName('manifest.json'), true)['media_files'])->toBe(2);
    $zip->close();

    // Lose the files, then restore them.
    Storage::disk('local')->deleteDirectory('media');
    expect(File::get($archive->extractDatabase($out['zip'], $this->dir.'/x')))->toBe('DB')
        ->and($archive->restoreFiles($out['zip']))->toBe(2);
    expect(Storage::disk('local')->get('media/album-photo/7/gallery_image-abc.webp'))->toBe('IMG')
        ->and(Storage::disk('local')->get('media/student/3/photo-xyz.webp'))->toBe('FACE');
});

it('never writes outside the media folder when restoring', function () {
    $zipPath = $this->dir.'/evil.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('database/db.sqlite', 'DB');
    $zip->addFromString('files/media/../../escape.txt', 'X');
    $zip->addFromString('files/media/ok/file.txt', 'OK');
    $zip->close();

    expect(app(MediaArchive::class)->restoreFiles($zipPath))->toBe(1);
    expect(Storage::disk('local')->exists('media/ok/file.txt'))->toBeTrue()
        ->and(is_file(Storage::disk('local')->path('escape.txt')))->toBeFalse();
});

it('rejects a zip that db:backup did not make', function () {
    $zipPath = $this->dir.'/other.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('hello.txt', 'hi');
    $zip->close();

    expect(Artisan::call('db:restore', ['file' => $zipPath, '--force' => true]))->toBe(1);
});

it('loads every language file (a PHP syntax error in one would only show in that locale)', function () {
    foreach (glob(lang_path('*/*.php')) as $file) {
        expect(require $file)->toBeArray();
    }
});
