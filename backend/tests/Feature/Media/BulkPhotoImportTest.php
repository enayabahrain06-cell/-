<?php

use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('ahl.media.disk', 'local');
});

function pngBytes(int $w = 300, int $h = 200): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 46, 107, 79));
    ob_start();
    imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);

    return $bytes;
}

it('matches photos by student number, id and phone, including siblings', function () {
    actingAsRole('supervisor');
    $a = Student::factory()->male()->create(['student_no' => 'S2600001']);
    $b = Student::factory()->male()->create();
    $sib1 = Student::factory()->male()->create(['guardian_phone' => '+97336009999']);
    $sib2 = Student::factory()->male()->create(['guardian_phone' => '+97336009999']);

    $files = [
        UploadedFile::fake()->createWithContent('S2600001.png', pngBytes()),
        UploadedFile::fake()->createWithContent("{$b->id}.png", pngBytes()),
        UploadedFile::fake()->createWithContent('36009999.png', pngBytes()),
        UploadedFile::fake()->createWithContent('unknown-name.png', pngBytes()),
        UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
    ];

    $res = $this->post('/api/students/photos/bulk', ['photos' => $files])->assertOk()->json();

    expect($res['summary']['matched'])->toBe(4)
        ->and($res['summary']['unmatched'])->toBe(2)
        ->and(collect($res['matched'])->pluck('student_id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id, $sib1->id, $sib2->id])->sort()->values()->all());

    foreach ([$a, $b, $sib1, $sib2] as $s) {
        expect($s->fresh()->photo_path)->not->toBeNull();
    }
});

it('imports from a zip archive', function () {
    actingAsRole('supervisor');
    $a = Student::factory()->male()->create(['student_no' => 'S2600002']);

    $zipPath = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('S2600002.png', pngBytes());
    $zip->addFromString('readme.txt', 'x');
    $zip->close();

    $archive = new UploadedFile($zipPath, 'photos.zip', 'application/zip', null, true);
    $res = $this->post('/api/students/photos/bulk', ['archive' => $archive])->assertOk()->json();

    expect($res['summary']['matched'])->toBe(1)->and($a->fresh()->photo_path)->not->toBeNull();
    @unlink($zipPath);
});

it('requires the students.photo permission', function () {
    actingAsRole('teacher');
    $this->post('/api/students/photos/bulk', ['photos' => [UploadedFile::fake()->createWithContent('1.png', pngBytes())]])->assertForbidden();
});
