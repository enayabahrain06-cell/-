<?php

namespace App\Http\Controllers\Api\Registration;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RegistrationRequestResource;
use App\Http\Resources\StudentSummaryResource;
use App\Models\Package;
use App\Models\RegistrationRequest;
use App\Services\Registration\AcceptRegistrationAction;
use App\Services\Registration\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Packages & registration
 */
class RegistrationRequestController extends Controller
{
    /** Filters: status, package_id, search (name / phone / request_no), from, to. Waitlist ordered by position. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RegistrationRequest::class);

        $q = RegistrationRequest::with(['package', 'student', 'decider'])
            ->tap(fn ($q) => \App\Support\Track::scope($q, $request->user()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('package_id'), fn ($q) => $q->where('package_id', $request->integer('package_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('full_name', 'like', $s)->orWhere('guardian_phone', 'like', $s)->orWhere('guardian_name', 'like', $s)->orWhere('request_no', 'like', $s));
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')));

        if ($request->string('status') === 'waitlist') {
            $q->orderBy('waitlist_position');
        } else {
            $q->orderByDesc('id');
        }

        return RegistrationRequestResource::collection($q->paginate((int) $request->integer('per_page', 25)))->response();
    }

    public function show(RegistrationRequest $registration): RegistrationRequestResource
    {
        $this->authorize('view', $registration);

        return new RegistrationRequestResource($registration->load(['package', 'student', 'decider', 'media']));
    }

    public function accept(Request $request, RegistrationRequest $registration, AcceptRegistrationAction $action): JsonResponse
    {
        $this->authorize('decide', $registration);

        $student = $action->execute($registration, $request->user()->id, $request->boolean('force'));

        return response()->json([
            'message' => __('registration.accepted'),
            'request' => new RegistrationRequestResource($registration->fresh(['package', 'student'])),
            'student' => new StudentSummaryResource($student),
        ]);
    }

    public function waitlist(Request $request, RegistrationRequest $registration, RegistrationService $service): JsonResponse
    {
        $this->authorize('decide', $registration);
        if ($registration->status === RegistrationStatus::Accepted) {
            throw ValidationException::withMessages(['status' => __('registration.errors.already_accepted')]);
        }
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['message' => __('api.saved'), 'request' => new RegistrationRequestResource($service->moveToWaitlist($registration, $request->user()->id, $data['note'] ?? null)->load('package'))]);
    }

    public function reject(Request $request, RegistrationRequest $registration, RegistrationService $service): JsonResponse
    {
        $this->authorize('decide', $registration);
        if ($registration->status === RegistrationStatus::Accepted) {
            throw ValidationException::withMessages(['status' => __('registration.errors.already_accepted')]);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['message' => __('api.saved'), 'request' => new RegistrationRequestResource($service->reject($registration, $data['reason'], $request->user()->id)->load('package'))]);
    }

    /**
     * Accept every matching request (pending by default; optionally waitlist) for a package while seats remain.
     * Returns counts and the ids that could not be accepted.
     */
    public function bulkAccept(Request $request, AcceptRegistrationAction $action): JsonResponse
    {
        $this->authorize('decide', RegistrationRequest::class);

        $data = $request->validate([
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['in:pending,waitlist'],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            'gender' => ['nullable', 'in:male,female'],
        ]);

        $package = Package::findOrFail($data['package_id']);
        $statuses = $data['statuses'] ?? ['pending'];

        abort_unless(\App\Support\Track::allows($request->user(), $package->gender), 403);
        $requests = RegistrationRequest::where('package_id', $package->id)
            ->whereIn('status', $statuses)
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->when(! empty($data['gender']), fn ($q) => $q->where('gender', $data['gender']))
            ->orderByRaw('1') // keep builder portable: ordering below in PHP
            ->get()
            ->sortBy(fn ($r) => [$r->status->value === 'pending' ? 0 : 1, $r->waitlist_position ?? 0, $r->id])
            ->values();

        $accepted = [];
        $skipped = [];

        foreach ($requests as $req) {
            if ($package->fresh()->isFull()) {
                $skipped[] = ['id' => $req->id, 'request_no' => $req->request_no, 'reason' => 'full'];

                continue;
            }
            try {
                $student = $action->execute($req, $request->user()->id);
                $accepted[] = ['id' => $req->id, 'request_no' => $req->request_no, 'student_id' => $student->id];
            } catch (ValidationException $e) {
                $skipped[] = ['id' => $req->id, 'request_no' => $req->request_no, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json([
            'message' => __('registration.bulk_done', ['count' => count($accepted)]),
            'accepted' => $accepted,
            'skipped' => $skipped,
            'seats_left' => max(0, $package->seats - $package->fresh()->acceptedCount()),
        ]);
    }
}
