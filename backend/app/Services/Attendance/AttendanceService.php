<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\LessonStudentStatus;
use App\Enums\SessionStatus;
use App\Jobs\SendAbsenceMessages;
use App\Models\Attendance;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function __construct(private RepeatedAbsenceDetector $repeated) {}

    /** Roster: every active student with their attendance row (or null) and current assignment. */
    public function roster(LessonSession $session): array
    {
        $enrolled = LessonStudent::with('student')
            ->where('lesson_id', $session->lesson_id)
            ->where('status', LessonStudentStatus::Active->value)
            ->get();

        $attendances = Attendance::where('lesson_session_id', $session->id)->get()->keyBy('student_id');

        return $enrolled->map(function (LessonStudent $ls) use ($attendances) {
            $a = $attendances->get($ls->student_id);

            return [
                'student' => $ls->student,
                'current_memorization' => $ls->current_memorization,
                'current_revision' => $ls->current_revision,
                'attendance' => $a,
            ];
        })->values()->all();
    }

    /**
     * Upsert attendance rows, update current assignments, mark the session held,
     * queue absence messages and detect repeated absence.
     *
     * @param  list<array{student_id:int,status:string,memorization_assignment?:string|null,revision_assignment?:string|null,note?:string|null}>  $records
     * @return array{saved:int, absent:int, repeated_absence_alerts:list<int>}
     */
    public function save(LessonSession $session, array $records, User $by): array
    {
        $absentStudentIds = [];

        DB::transaction(function () use ($session, $records, $by, &$absentStudentIds) {
            foreach ($records as $r) {
                $attendance = Attendance::updateOrCreate(
                    ['lesson_session_id' => $session->id, 'student_id' => $r['student_id']],
                    [
                        'status' => $r['status'],
                        'memorization_assignment' => $r['memorization_assignment'] ?? null,
                        'revision_assignment' => $r['revision_assignment'] ?? null,
                        'note' => $r['note'] ?? null,
                        'recorded_by' => $by->id,
                    ]
                );

                // Today's assignment becomes the student's current assignment in the circle.
                $update = array_filter([
                    'current_memorization' => $r['memorization_assignment'] ?? null,
                    'current_revision' => $r['revision_assignment'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
                if ($update) {
                    LessonStudent::where('lesson_id', $session->lesson_id)->where('student_id', $r['student_id'])->update($update);
                }

                if ($attendance->status === AttendanceStatus::Absent) {
                    $absentStudentIds[] = $r['student_id'];
                }
            }

            $session->update([
                'attendance_taken_at' => now(),
                'taken_by' => $by->id,
                'status' => SessionStatus::Held,
            ]);
        });

        if ($absentStudentIds) {
            SendAbsenceMessages::dispatch($session->id);
        }

        $alerts = [];
        foreach (array_unique($absentStudentIds) as $studentId) {
            if ($alert = $this->repeated->check($studentId)) {
                $alerts[] = $alert->id;
            }
        }

        return ['saved' => count($records), 'absent' => count($absentStudentIds), 'repeated_absence_alerts' => $alerts];
    }

    public function markAllPresent(LessonSession $session, User $by): array
    {
        $records = LessonStudent::where('lesson_id', $session->lesson_id)
            ->where('status', LessonStudentStatus::Active->value)
            ->pluck('student_id')
            ->map(fn ($id) => ['student_id' => $id, 'status' => AttendanceStatus::Present->value])
            ->all();

        return $this->save($session, $records, $by);
    }
}
