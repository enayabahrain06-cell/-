<?php

namespace App\Http\Controllers\Api\Progress;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Policies\StudentIssuePolicy;
use App\Services\Students\StudentProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Memorization & evaluation
 */
class StudentProfileController extends Controller
{
    /**
     * Full profile: header (photo, age, package, teacher, position, attendance %, balance, open issues),
     * Quran position and juz map, evaluation summary and open difficulties with action plans.
     * Guardians and the student receive the same payload read-only, in their saved language.
     */
    public function show(Request $request, Student $student, StudentProfileService $profiles): JsonResponse
    {
        $this->authorize('view', $student);

        // Locale already resolved by SetLocale: the user's saved locale, else Accept-Language.
        $readOnly = ! StudentIssuePolicy::canWriteFor($request->user(), $student);
        $locale = app()->getLocale();

        return response()->json([
            'data' => $profiles->build($student, $locale),
            'meta' => ['read_only' => $readOnly, 'locale' => $locale],
        ]);
    }
}
