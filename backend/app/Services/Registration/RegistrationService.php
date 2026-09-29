<?php

namespace App\Services\Registration;

use App\Enums\AlertType;
use App\Enums\Gender;
use App\Enums\MessageType;
use App\Enums\RegistrationStatus;
use App\Models\Alert;
use App\Models\Package;
use App\Models\RegistrationRequest;
use App\Services\Messaging\MessageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrationService
{
    public function __construct(private MessageService $messages) {}

    /** Public self-registration. Validated data comes from StoreRegistrationRequest (age/gender already rejected there). */
    public function submit(Package $package, array $data, ?UploadedFile $photo = null): RegistrationRequest
    {
        $check = PackageSuitability::check($package, \Carbon\Carbon::parse($data['birth_date']), Gender::from($data['gender']));
        if (! $check['suitable']) {
            throw ValidationException::withMessages(['package_id' => __('registration.errors.'.$check['reason'])]);
        }

        $request = DB::transaction(function () use ($package, $data, $check) {
            $status = $check['is_full'] ? RegistrationStatus::Waitlist : RegistrationStatus::Pending;

            $request = RegistrationRequest::create([
                'request_no' => RegistrationRequest::nextRequestNo(),
                'package_id' => $package->id,
                'full_name' => $data['full_name'],
                'birth_date' => $data['birth_date'],
                'gender' => $data['gender'],
                'student_phone' => $data['student_phone'] ?? null,
                'guardian_name' => $data['guardian_name'],
                'guardian_phone' => $data['guardian_phone'],
                'memorization_level' => $data['memorization_level'],
                'locale' => $data['locale'] ?? 'ar',
                'age_at_start' => $check['age_at_start'],
                'status' => $status,
                'waitlist_position' => $status === RegistrationStatus::Waitlist ? $this->nextWaitlistPosition($package) : null,
                'notes' => $data['notes'] ?? null,
            ]);

            // StoreRegistrationRequest already checked the token; link inside the same transaction.
            $placement = app(\App\Services\Exams\PlacementService::class);
            if ($attempt = $placement->usableAttempt($data['placement_token'] ?? null, $package->id)) {
                $placement->attachToRequest($attempt, $request);
            }

            return $request;
        });

        if ($photo) {
            $this->attachPhoto($request, $photo);
        }

        $this->refreshPendingAlert($package);

        $this->notify($request, MessageType::RegistrationReceived, [
            'request_no' => $request->request_no,
            'package' => $package->localizedName($request->locale->value),
            'link' => config('ahl.frontend_url').'/track/'.$request->request_no,
        ]);

        return $request;
    }

    public function moveToWaitlist(RegistrationRequest $request, ?int $by = null, ?string $note = null): RegistrationRequest
    {
        DB::transaction(function () use ($request, $by, $note) {
            if ($request->status !== RegistrationStatus::Waitlist) {
                $request->update([
                    'status' => RegistrationStatus::Waitlist,
                    'waitlist_position' => $this->nextWaitlistPosition($request->package),
                    'decided_by' => $by ?? auth()->id(),
                    'decided_at' => now(),
                    'notes' => $note ?? $request->notes,
                ]);
            }
        });

        $this->refreshPendingAlert($request->package);
        $this->notify($request, MessageType::RegistrationWaitlist, ['request_no' => $request->request_no, 'package' => $request->package->localizedName($request->locale->value)]);

        return $request->fresh();
    }

    public function reject(RegistrationRequest $request, string $reason, ?int $by = null): RegistrationRequest
    {
        DB::transaction(function () use ($request, $reason, $by) {
            $wasWaitlist = $request->status === RegistrationStatus::Waitlist;
            $request->update([
                'status' => RegistrationStatus::Rejected,
                'waitlist_position' => null,
                'reason' => $reason,
                'decided_by' => $by ?? auth()->id(),
                'decided_at' => now(),
            ]);
            if ($wasWaitlist) {
                $this->repackWaitlist($request->package);
            }
        });

        $this->refreshPendingAlert($request->package);
        $this->notify($request, MessageType::RegistrationRejected, ['request_no' => $request->request_no, 'package' => $request->package->localizedName($request->locale->value)]);

        return $request->fresh();
    }

    /** Waitlist positions are contiguous 1..n per package, ordered by original position then id. */
    public function repackWaitlist(Package $package): void
    {
        $rows = RegistrationRequest::where('package_id', $package->id)
            ->where('status', RegistrationStatus::Waitlist->value)
            ->orderBy('waitlist_position')->orderBy('id')->get();

        foreach ($rows as $i => $row) {
            if ($row->waitlist_position !== $i + 1) {
                $row->update(['waitlist_position' => $i + 1]);
            }
        }
    }

    public function nextWaitlistPosition(Package $package): int
    {
        return (int) RegistrationRequest::where('package_id', $package->id)
            ->where('status', RegistrationStatus::Waitlist->value)->max('waitlist_position') + 1;
    }

    /** Dashboard alert: one open alert per package while pending requests exist. */
    public function refreshPendingAlert(Package $package): void
    {
        $pending = RegistrationRequest::where('package_id', $package->id)->where('status', RegistrationStatus::Pending->value)->count();

        if ($pending > 0) {
            $alert = Alert::raise(AlertType::RegistrationRequest, __('registration.alerts.pending_title', ['count' => $pending, 'package' => $package->name]), null, $package);
            $alert->update(['title' => __('registration.alerts.pending_title', ['count' => $pending, 'package' => $package->name])]);
        } else {
            Alert::resolveFor(AlertType::RegistrationRequest, $package);
        }
    }

    public function notify(RegistrationRequest $request, MessageType $type, array $vars): void
    {
        $locale = $request->locale->value;
        foreach (array_unique(array_filter([$request->guardian_phone, $request->student_phone])) as $phone) {
            $this->messages->send($phone, $type, $vars + ['name' => $request->full_name], $locale, $request->student);
        }
    }

    /** The request's own photo (moved to the student on acceptance). setPhoto() is for students only. */
    public function attachPhoto(RegistrationRequest $request, UploadedFile $photo): void
    {
        app(\App\Services\Media\StudentPhotoService::class)->setRequestPhoto($request, $photo);
    }
}
