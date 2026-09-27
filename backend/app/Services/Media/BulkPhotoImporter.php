<?php

namespace App\Services\Media;

use App\Models\Student;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One-time bulk import: images named by student number (S2600012.jpg), numeric student id
 * (12.jpg) or phone digits (36000001.jpg / 97336000001.jpg → student or guardian phone).
 */
class BulkPhotoImporter
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'heic', 'heif', 'webp'];

    public function __construct(private StudentPhotoService $photos) {}

    /**
     * @param  array<string, string>  $files  original filename => absolute path on disk
     * @return array{matched: list<array>, unmatched: list<array>, errors: list<array>}
     */
    public function import(array $files): array
    {
        $report = ['matched' => [], 'unmatched' => [], 'errors' => []];

        foreach ($files as $name => $path) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! in_array($ext, self::IMAGE_EXT, true)) {
                $report['unmatched'][] = ['file' => $name, 'reason' => __('media.bulk.not_image')];

                continue;
            }

            $students = $this->match(pathinfo($name, PATHINFO_FILENAME));
            if ($students->isEmpty()) {
                $report['unmatched'][] = ['file' => $name, 'reason' => __('media.bulk.no_match')];

                continue;
            }

            foreach ($students as $student) {
                try {
                    $this->photos->setPhoto($student, $path);
                    $report['matched'][] = ['file' => $name, 'student_id' => $student->id, 'student_no' => $student->student_no, 'full_name' => $student->full_name];
                } catch (ValidationException $e) {
                    $report['errors'][] = ['file' => $name, 'student_id' => $student->id, 'reason' => implode(' ', $e->validator->errors()->all())];
                } catch (\Throwable $e) {
                    $report['errors'][] = ['file' => $name, 'student_id' => $student->id, 'reason' => $e->getMessage()];
                }
            }
        }

        return $report;
    }

    /** Extract a zip into a temp dir and return name => path for every regular file. */
    public function extractZip(string $zipPath, string $tempDir): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw ValidationException::withMessages(['archive' => __('media.bulk.bad_zip')]);
        }

        File::ensureDirectoryExists($tempDir);
        $zip->extractTo($tempDir);
        $zip->close();

        $files = [];
        foreach (File::allFiles($tempDir) as $file) {
            if (Str::startsWith($file->getFilename(), ['.', '__MACOSX'])) {
                continue;
            }
            $files[$file->getFilename()] = $file->getPathname();
        }

        return $files;
    }

    /** @return \Illuminate\Support\Collection<int, Student> */
    private function match(string $stem): \Illuminate\Support\Collection
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', PhoneNumber::toLatinDigits($stem)));

        if ($clean === '') {
            return collect();
        }

        if (preg_match('/^S\d+$/', $clean)) {
            return Student::where('student_no', $clean)->get();
        }

        if (ctype_digit($clean)) {
            if (strlen($clean) <= 6) {
                $byId = Student::where('id', (int) $clean)->get();
                if ($byId->isNotEmpty()) {
                    return $byId;
                }
            }

            $phone = PhoneNumber::normalize($clean);
            if ($phone) {
                return Student::where('student_phone', $phone)->orWhere('guardian_phone', $phone)->get();
            }
        }

        return collect();
    }
}
