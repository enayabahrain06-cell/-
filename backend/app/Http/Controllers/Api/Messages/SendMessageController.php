<?php

namespace App\Http\Controllers\Api\Messages;

use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\SendMessageRequest;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Services\Messaging\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * @group Messages — send
 */
class SendMessageController extends Controller
{
    /**
     * Send a custom WhatsApp message to students (by id) or raw phones.
     * Teachers may only target students enrolled in their own circles.
     */
    public function __invoke(SendMessageRequest $request, MessageService $messages): JsonResponse
    {
        $user = $request->user();
        $body = $request->string('body')->toString();
        $to = $request->string('to', 'guardian')->toString();
        $queued = [];

        if ($request->filled('student_ids')) {
            $ids = array_unique(array_map('intval', $request->input('student_ids')));

            if ($user->hasRole('teacher') && ! $user->hasRole('super_admin') && ! $user->hasRole('supervisor')) {
                $allowed = LessonStudent::whereIn('student_id', $ids)->where('status', 'active')
                    ->whereIn('lesson_id', Lesson::where('teacher_id', $user->id)->select('id'))
                    ->pluck('student_id')->unique()->all();
                $denied = array_values(array_diff($ids, $allowed));
                if ($denied) {
                    throw ValidationException::withMessages(['student_ids' => __('messages.not_your_students')]);
                }
            }

            foreach (Student::whereIn('id', $ids)->get() as $student) {
                $targets = match ($to) {
                    'student' => [[$student->primaryPhone(), RecipientType::Student]],
                    'both' => array_values(array_filter([
                        [$student->guardian_phone, RecipientType::Guardian],
                        $student->student_phone ? [$student->student_phone, RecipientType::Student] : null,
                    ])),
                    default => [[$student->guardian_phone, RecipientType::Guardian]],
                };

                foreach ($targets as [$phone, $type]) {
                    $log = $messages->send(
                        phone: $phone,
                        type: MessageType::Custom,
                        vars: ['body' => $body],
                        locale: $request->input('locale') ?: $student->locale?->value,
                        student: $student,
                        recipientType: $type,
                        templateKey: null,
                    );
                    if ($log) {
                        $queued[] = $log->id;
                    }
                }
            }
        }

        if ($request->filled('phones')) {
            abort_unless($user->can('messages.manage'), 403, __('messages.phones_need_manage'));

            foreach (array_unique($request->input('phones')) as $phone) {
                $log = $messages->send(
                    phone: (string) $phone,
                    type: MessageType::Custom,
                    vars: ['body' => $body],
                    locale: $request->input('locale') ?: 'ar',
                    recipientType: RecipientType::User,
                    templateKey: null,
                );
                if ($log) {
                    $queued[] = $log->id;
                }
            }
        }

        return response()->json(['message' => __('messages.queued', ['count' => count($queued)]), 'count' => count($queued), 'log_ids' => $queued]);
    }
}
