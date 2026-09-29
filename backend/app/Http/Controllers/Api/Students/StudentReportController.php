<?php

namespace App\Http\Controllers\Api\Students;

use Ahl\Certificates\Enums\CertificateStatus;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\Pdf\PdfService;
use App\Services\Students\StudentProfileService;
use Illuminate\Http\Response;

/**
 * @group Students
 */
class StudentReportController extends Controller
{
    /**
     * Printed student report (PDF): details, memorization position, attendance, evaluation average
     * and the list of approved certificates. Same audience as the profile.
     */
    public function show(Student $student, StudentProfileService $profiles, PdfService $pdf): Response
    {
        $this->authorize('view', $student);
        $profile = $profiles->build($student, 'ar');

        $bytes = $pdf->render('pdf.student-report', [
            'student' => $student,
            'profile' => $profile,
            'certificates' => $student->certificates()->where('status', CertificateStatus::Approved->value)
                ->orderByDesc('issued_on')->orderByDesc('id')->get(),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="student-'.$student->student_no.'.pdf"',
        ]);
    }
}
