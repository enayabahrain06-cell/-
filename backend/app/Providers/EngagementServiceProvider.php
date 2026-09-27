<?php

namespace App\Providers;

use App\Models\Attendance;
use App\Models\ChallengeParticipant;
use App\Models\Evaluation;
use App\Models\StudentProgress;
use App\Services\Engagement\ChallengeService;
use Illuminate\Support\ServiceProvider;

/** Challenge progress is recomputed from the ledgers whenever attendance, an evaluation or a ledger entry is saved. */
class EngagementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $refresh = function ($model) {
            $studentId = (int) $model->student_id;
            if ($studentId && ChallengeParticipant::where('student_id', $studentId)->where('status', 'joined')->exists()) {
                app(ChallengeService::class)->refreshStudent($studentId);
            }
        };

        Attendance::saved($refresh);
        Evaluation::saved($refresh);
        StudentProgress::saved($refresh);
    }
}
