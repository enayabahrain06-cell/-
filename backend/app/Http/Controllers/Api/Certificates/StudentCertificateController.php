<?php

namespace App\Http\Controllers\Api\Certificates;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Student;
use App\Policies\CertificatePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Certificates
 */
class StudentCertificateController extends Controller
{
    /**
     * The student profile "Certificates" tab: every certificate, newest first, with a summary
     * (approved total, memorization, excellence, latest). Drafts are listed for staff only;
     * the student and guardian see approved and revoked ones read-only.
     */
    public function index(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);
        $staff = CertificatePolicy::isStaffFor($request->user(), $student);

        $certificates = $student->certificates()->with(['student', 'lesson:id,name', 'approver:id,name'])
            ->when(! $staff, fn ($q) => $q->where('status', '!=', CertificateStatus::Draft->value))
            ->orderByDesc('issued_on')->orderByDesc('id')->get();

        $approved = $certificates->where('status', CertificateStatus::Approved);
        $latest = $approved->first();

        return response()->json([
            'data' => CertificateResource::collection($certificates),
            'summary' => [
                'total' => $approved->count(),
                'memorization' => $approved->where('type', CertificateType::Completion)->count(),
                'excellence' => $approved->where('type', CertificateType::Excellence)->count(),
                'drafts' => $staff ? $certificates->where('status', CertificateStatus::Draft)->count() : null,
                'latest' => $latest ? ['id' => $latest->id, 'title' => $latest->title, 'achievement' => $latest->achievement, 'issued_on' => $latest->issued_on?->toDateString()] : null,
            ],
            'meta' => [
                'read_only' => ! $staff,
                'can_issue' => $request->user()->can('issueFor', [\App\Models\Certificate::class, $student]),
            ],
        ]);
    }
}
