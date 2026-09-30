<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\ExcuseStatus;
use App\Enums\LessonStudentStatus;
use App\Enums\SessionStatus;
use App\Jobs\SendAbsenceMessages;
use App\Models\Attendance;
use App\Models\AttendanceConfirmation;
use App\Models\AttendanceExcuse;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attendance is taken by the circle's teacher (or a supervisor) per session: status, today's
 * assignment and a note per student. Saving again after the first save is a correction: each
 * changed row is written to the audit log with its old and new values.
 */
class AttendanceService
{

    public function __construct(
        private RepeatedAbsenceDetector $repeated,
        private \App\Services\Progress\ProgressService $progress,
        private AuditLogger $audit,
    ) {}

    /** Roster: every active student with their attendance row (or null), current assignment, confirmation and latest excuse. */
    public function roster(LessonSession $session): array
    {
        $enrolled = LessonStudent::with('student')
            ->where('lesson_id', $session->lesson_id)
            ->where('status', LessonStudentStatus::Active->value)
            ->get();

        $attendances = Attendance::where('lesson_session_id', $session->id)->get()->keyBy('student_id');
        $confirmations = AttendanceConfirmation::where('lesson_session_id', $session->id)->get()->keyBy('student_id');
        $excuses = AttendanceExcuse::where('lesson_session_id', $session->id)->orderBy('id')->get()->keyBy('student_id');

        return $enrolled->map(function (LessonStudent $ls) use ($attendances, $confirmations, $excuses) {
            return [
                'student' => $ls->student,
                'current_memorization' => $ls->current_memorization,
                'current_revision' => $ls->current_revision,
                'attendance' => $attendances->get($ls->student_id),
                'confirmation' => $confirmations->get($ls->student_id),
                'excuse' => $excuses->get($ls->student_id),
            ];
        })->values()->all();
    }

    /**
     * Upsert attendance rows, update current assignments, mark the session held,
     * queue absence messages and detect repeated absence.
     * Only the keys present in a record are written, so "status only" keeps the assignment and note.
     *
     * @param  list<array{student_id:int,status:string,memorization_assignment?:string|null,revision_assignment?:string|null,note?:string|null}>  $records
     * @return array{saved:int, absent:int, corrected:int, repeated_absence_alerts:list<int>}
     */
    public function save(LessonSession $session, array $records, User $by): array
    {
        if ($session->status === SessionStatus::Cancelled) {
            throw ValidationException::withMessages(['session' => __('attendance.session_cancelled')]);
        }

        $absentStudentIds = [];
        $corrected = 0;
        $isCorrection = $session->attendance_taken_at !== null;
        $isTeacher = $session->lesson && \App\Support\TeacherScope::teaches($by, $session->lesson);

        DB::transaction(function () use ($session, $records, $by, $isCorrection, $isTeacher, &$absentStudentIds, &$corrected) {
            $existing = Attendance::where('lesson_session_id', $session->id)->get()->keyBy('student_id');

            foreach ($records as $r) {
                $values = ['status' => $r['status'], 'recorded_by' => $by->id];
                foreach (['memorization_assignment', 'revision_assignment', 'note'] as $k) {
                    if (array_key_exists($k, $r)) {
                        $values[$k] = $r[$k];
                    }
                }

                $before = $existing->get($r['student_id']);
                $old = $before ? $this->snapshot($before) : null;

                $attendance = Attendance::updateOrCreate(
                    ['lesson_session_id' => $session->id, 'student_id' => $r['student_id']],
                    $values
                );

                if ($isCorrection && $old !== null) {
                    $new = $this->snapshot($attendance);
                    $changedKeys = array_keys(array_filter($new, fn ($v, $k) => $old[$k] !== $v, ARRAY_FILTER_USE_BOTH));
                    if ($changedKeys) {
                        $corrected++;
                        $this->audit->record(
                            'attendance.corrected',
                            $attendance,
                            array_intersect_key($old, array_flip($changedKeys)),
                            array_intersect_key($new, array_flip($changedKeys)) + ['session_id' => $session->id, 'by_teacher' => $isTeacher],
                            $by->id,
                        );
                    }
                }

                // Today's assignment becomes the student's current assignment in the circle.
                $update = array_filter([
                    'current_memorization' => $r['memorization_assignment'] ?? null,
                    'current_revision' => $r['revision_assignment'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
                if ($update) {
                    LessonStudent::where('lesson_id', $session->lesson_id)->where('student_id', $r['student_id'])
                        ->where('status', LessonStudentStatus::Active->value)->update($update);
                }

                foreach ($r['progress'] ?? [] as $entry) {
                    $this->progress->append($attendance->student, $entry + ['lesson_id' => $session->lesson_id, 'recorded_on' => $session->session_date->toDateString()], $by->id);
                }

                if ($attendance->status === AttendanceStatus::Absent) {
                    $absentStudentIds[] = $r['student_id'];
                }
            }

            // The first save records who took attendance and when; later saves are corrections.
            $session->update(array_filter([
                'attendance_taken_at' => $session->attendance_taken_at ? null : now(),
                'taken_by' => $session->taken_by ? null : $by->id,
                'status' => SessionStatus::Held,
            ], fn ($v) => $v !== null));
        });

        if ($absentStudentIds) {
            SendAbsenceMessages::dispatch($session->id);
        }

        $alerts = [];
        foreach (array_unique($absentStudentIds) as $studentId) {
            if ($alert = $this->repeated->check($studentId, $session)) {
                $alerts[] = $alert->id;
            }
        }

        return ['saved' => count($records), 'absent' => count($absentStudentIds), 'corrected' => $corrected, 'repeated_absence_alerts' => $alerts];
    }

    /** Everyone present; assignments and notes already entered are kept. */
    public function markAllPresent(LessonSession $session, User $by): array
    {
        $records = LessonStudent::where('lesson_id', $session->lesson_id)
            ->where('status', LessonStudentStatus::Active->value)
            ->pluck('student_id')
            ->map(fn ($id) => ['student_id' => $id, 'status' => AttendanceStatus::Present->value])
            ->all();

        return $this->save($session, $records, $by);
    }

    /** The teacher (or a supervisor) accepts an excuse received after attendance was taken. */
    public function approveExcuse(AttendanceExcuse $excuse, User $by): AttendanceExcuse
    {
        return DB::transaction(function () use ($excuse, $by) {
            $attendance = Attendance::firstOrNew(['lesson_session_id' => $excuse->lesson_session_id, 'student_id' => $excuse->student_id]);
            $old = $attendance->exists ? $this->snapshot($attendance) : [];
            $attendance->fill([
                'status' => AttendanceStatus::Excused,
                'note' => trim(($attendance->note ? $attendance->note.' — ' : '').__('attendance.excuse_note', [], 'ar')),
                'recorded_by' => $by->id,
            ])->save();

            $excuse->update(['status' => ExcuseStatus::Approved, 'attendance_id' => $attendance->id, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
            $this->audit->record('attendance.excuse_approved', $attendance, $old, $this->snapshot($attendance->fresh()) + ['excuse_id' => $excuse->id], $by->id);

            return $excuse->fresh();
        });
    }

    public function rejectExcuse(AttendanceExcuse $excuse, User $by): AttendanceExcuse
    {
        $excuse->update(['status' => ExcuseStatus::Rejected, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        $this->audit->record('attendance.excuse_rejected', $excuse, [], ['session_id' => $excuse->lesson_session_id, 'student_id' => $excuse->student_id], $by->id);

        return $excuse->fresh();
    }

    /** @return array<string, string|null> */
    private function snapshot(Attendance $a): array
    {
        return [
            'status' => $a->status?->value,
            'memorization_assignment' => $a->memorization_assignment,
            'revision_assignment' => $a->revision_assignment,
            'note' => $a->note,
        ];
    }
}
