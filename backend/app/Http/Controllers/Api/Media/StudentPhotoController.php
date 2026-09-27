<?php

namespace App\Http\Controllers\Api\Media;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentSummaryResource;
use App\Models\Student;
use App\Services\Media\BulkPhotoImporter;
use App\Services\Media\StudentPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * @group Students — photos
 */
class StudentPhotoController extends Controller
{
    /** Upload or replace a student's photo (JPG/PNG/HEIC ≤ 5 MB). Stored as 512 px + 96 px WebP; original discarded. */
    public function store(Request $request, Student $student, StudentPhotoService $photos): StudentSummaryResource
    {
        Gate::authorize('update-photo', $student);

        $request->validate(['photo' => ['required', 'file', 'max:'.(int) config('ahl.media.photo.max_kb', 5120)]]);

        $photos->setPhoto($student, $request->file('photo'));

        return new StudentSummaryResource($student->fresh());
    }

    public function destroy(Student $student, StudentPhotoService $photos): JsonResponse
    {
        Gate::authorize('update-photo', $student);

        $photos->removePhoto($student);

        return response()->json(['message' => __('media.photo_removed')]);
    }

    /** 10-minute signed URLs for the two variants (policy-checked). */
    public function url(Student $student): JsonResponse
    {
        Gate::authorize('view-photo', $student);

        return response()->json($student->photoUrls() + ['has_photo' => $student->photo_path !== null, 'initial' => $student->initial()]);
    }

    /** Bulk import: a ZIP archive or several images, matched by student no / id / phone in the filename. */
    public function bulk(Request $request, BulkPhotoImporter $importer): JsonResponse
    {
        Gate::authorize('bulk-photos');

        $request->validate([
            'archive' => ['required_without:photos', 'file', 'mimes:zip', 'max:204800'],
            'photos' => ['required_without:archive', 'array', 'max:500'],
            'photos.*' => ['file', 'max:'.(int) config('ahl.media.photo.max_kb', 5120)],
        ]);

        $tempDir = storage_path('app/tmp/bulk-photos/'.Str::lower(Str::random(10)));
        $files = [];

        try {
            if ($request->hasFile('archive')) {
                $files = $importer->extractZip($request->file('archive')->getRealPath(), $tempDir);
            } else {
                foreach ($request->file('photos', []) as $upload) {
                    $files[$upload->getClientOriginalName()] = $upload->getRealPath();
                }
            }

            $report = $importer->import($files);
        } finally {
            File::deleteDirectory($tempDir);
        }

        return response()->json($report + ['summary' => [
            'matched' => count($report['matched']),
            'unmatched' => count($report['unmatched']),
            'errors' => count($report['errors']),
        ]]);
    }
}
