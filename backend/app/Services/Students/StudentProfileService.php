<?php

namespace App\Services\Students;

use App\Enums\AttendanceStatus;
use App\Enums\CertificateStatus;
use Illuminate\Support\Facades\DB;
use App\Enums\IssueSeverity;
use App\Models\Attendance;
use App\Models\Student;
use App\Services\Evaluation\EvaluationService;
use App\Services\Issues\IssueService;
use App\Services\Progress\ProgressService;

/**
 * One payload for the student profile. Staff and guardians get the same content;
 * the guardian's copy is read-only (enforced by policies) and rendered in their locale.
 */
class StudentProfileService
{
    public function __construct(
        private ProgressService $progress,
        private EvaluationService $evaluations,
        private IssueService $issues,
    ) {}

    public function build(Student $student, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $student->loadMissing(['wallet', 'activeLessons.teacher', 'activeLessons.package']);
        $lesson = $student->activeLessons->sortBy('pivot.joined_at')->first();
        $package = $lesson?->package;

        $progress = $this->progress->summary($student, $locale);
        $openIssues = $student->issues()->unresolved()->with(['opener:id,name', 'notes.author:id,name'])
            ->orderByDesc('opened_at')->get()
            ->sortBy(fn ($i) => array_search($i->severity, [IssueSeverity::High, IssueSeverity::Medium, IssueSeverity::Low], true))
            ->values();

        $balance = $student->wallet?->balance_fils ?? 0;

        return [
            'header' => [
                'id' => $student->id,
                'student_no' => $student->student_no,
                'full_name' => $student->full_name,
                'initial' => $student->initial(),
                'photo_url' => $student->photoUrl('profile'),
                'gender' => $student->gender?->value,
                'age' => $student->birth_date ? (int) $student->birth_date->diffInYears(now(), true) : null,
                'package' => $package ? ['id' => $package->id, 'name' => $package->localizedName($locale)] : null,
                'lesson' => $lesson ? ['id' => $lesson->id, 'name' => $lesson->name] : null,
                'teacher' => $lesson?->teacher?->name,
                'position' => $progress['position'],
                'attendance_percent' => $this->attendancePercent($student),
                'balance_fils' => $balance,
                'is_due' => $balance < 0,
                'certificates_count' => $student->certificates()->where('status', CertificateStatus::Approved->value)->count(),
                'badges_count' => DB::table('student_badges')->where('student_id', $student->id)->count(),
                'open_issues' => [
                    'total' => $openIssues->count(),
                    'by_severity' => collect(IssueSeverity::cases())->mapWithKeys(fn ($s) => [$s->value => $openIssues->where('severity', $s)->count()])->all(),
                ],
            ],
            'progress' => $progress,
            'evaluation' => $this->evaluations->summary($student, $locale),
            'issues' => $openIssues->map(fn ($i) => $this->issues->present($i, $locale))->values()->all(),
        ];
    }

    /** (present + late) / (all recorded − excused), whole percent; null when nothing recorded. */
    public function attendancePercent(Student $student): ?int
    {
        $counts = Attendance::where('student_id', $student->id)->get(['status'])->countBy(fn ($a) => $a->status->value);
        $counted = $counts->sum() - ($counts[AttendanceStatus::Excused->value] ?? 0);
        if ($counted <= 0) {
            return null;
        }
        $attended = ($counts[AttendanceStatus::Present->value] ?? 0) + ($counts[AttendanceStatus::Late->value] ?? 0);

        return (int) round($attended * 100 / $counted);
    }
}
