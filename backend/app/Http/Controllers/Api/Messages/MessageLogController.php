<?php

namespace App\Http\Controllers\Api\Messages;

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageLogResource;
use App\Models\MessageLog;
use App\Services\Messaging\MessageService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Messages — logs
 */
class MessageLogController extends Controller
{
    /** Filters: status, type, phone, student_id, from, to (dates), per_page. */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('messages.view'), 403);

        // Scoped staff only see messages about students in their track (staff/system messages need both tracks).
        $logs = MessageLog::with(['student:id,full_name', 'user:id,name'])
            ->tap(fn ($q) => \App\Support\Track::scopeVia($q, $request->user(), 'student'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('phone'), function ($q) use ($request) {
                $phone = PhoneNumber::normalize($request->string('phone')) ?? $request->string('phone');
                $q->where('recipient_phone', 'like', '%'.ltrim((string) $phone, '+').'%');
            })
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 25));

        return MessageLogResource::collection($logs);
    }

    public function show(Request $request, MessageLog $log): MessageLogResource
    {
        abort_unless($request->user()->can('messages.view'), 403);
        abort_unless(\App\Support\Track::genderFor($request->user()) === null || (\App\Support\Track::allows($request->user(), $log->student?->gender) && $log->student_id), 403);

        return new MessageLogResource($log->load(['student:id,full_name', 'user:id,name']));
    }

    public function resend(Request $request, MessageLog $log, MessageService $messages): MessageLogResource
    {
        abort_unless($request->user()->can('messages.manage'), 403);

        return new MessageLogResource($messages->resend($log)->fresh());
    }

    /** Re-queue every failed message, optionally within a date range. */
    public function resendFailed(Request $request, MessageService $messages): JsonResponse
    {
        abort_unless($request->user()->can('messages.manage'), 403);

        $query = MessageLog::where('status', MessageStatus::Failed->value)
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()));

        $count = 0;
        $query->orderBy('id')->chunkById(200, function ($logs) use ($messages, &$count) {
            foreach ($logs as $log) {
                $messages->resend($log);
                $count++;
            }
        });

        return response()->json(['message' => __('messages.requeued', ['count' => $count]), 'count' => $count]);
    }

    /** Counts by status and by type. */
    public function stats(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('messages.view'), 403);

        $base = MessageLog::query()
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()));

        // Portable aggregates only (COUNT per value), no driver-specific SQL.
        $byStatus = collect(MessageStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (clone $base)->where('status', $s->value)->count()]);
        $byType = collect(MessageType::cases())->mapWithKeys(fn ($t) => [$t->value => (clone $base)->where('type', $t->value)->count()])->filter();

        return response()->json([
            'total' => (int) $byStatus->sum(),
            'by_status' => $byStatus,
            'by_type' => $byType,
        ]);
    }
}
