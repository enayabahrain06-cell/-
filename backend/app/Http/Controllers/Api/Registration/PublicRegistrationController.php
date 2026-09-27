<?php

namespace App\Http\Controllers\Api\Registration;

use App\Enums\Gender;
use App\Enums\PackageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\StoreRegistrationRequest;
use App\Http\Resources\PackageResource;
use App\Http\Resources\RegistrationRequestResource;
use App\Models\Package;
use App\Models\RegistrationRequest;
use App\Services\Registration\PackageSuitability;
use App\Services\Registration\RegistrationService;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Public registration
 * @unauthenticated
 */
class PublicRegistrationController extends Controller
{
    /** Public settings needed by the registration and login pages. */
    public function settings(): JsonResponse
    {
        return response()->json([
            'authority' => [
                'name_ar' => setting('authority.name_ar', config('ahl.authority.name_ar')),
                'name_en' => setting('authority.name_en', config('ahl.authority.name_en')),
                'address_ar' => setting('authority.address_ar'),
                'address_en' => setting('authority.address_en'),
                'phone' => setting('authority.phone'),
            ],
            'country_code' => setting('locale.country_code', config('ahl.country_code')),
            'currency' => setting('locale.currency', config('ahl.currency')),
            'show_hijri' => (bool) setting('locale.show_hijri', true),
            'default_locale' => setting('locale.default', 'ar'),
            'registration_open' => (bool) setting('registration.open', true),
            'photo_required' => (bool) setting('registration.photo_required', false),
            'memorization_levels' => \App\Enums\MemorizationLevel::options(),
            'ornament_level' => in_array($level = setting('ui.ornament_level', 'full'), ['full', 'minimal', 'off'], true) ? $level : 'full',
        ]);
    }

    /**
     * Open packages. Pass birth_date and gender to get per-package suitability
     * (locked with a reason, or full → waitlist offer).
     */
    public function packages(Request $request): AnonymousResourceCollection
    {
        // Gender first: boys see boys' packages, girls see girls' packages; both see mixed early-years packages.
        $request->validate(['gender' => ['required', Gender::rule()], 'birth_date' => ['nullable', 'string', 'max:20']]);
        $gender = Gender::from($request->string('gender')->toString());

        $packages = Package::where('status', PackageStatus::Open->value)->whereIn('gender', [$gender->value, \App\Enums\PackageGender::Mixed->value])->orderBy('start_date')->get();

        if ($request->filled('birth_date')) {
            $birth = Carbon::parse(PhoneNumber::toLatinDigits($request->string('birth_date')));
            $packages->each(fn (Package $p) => $p->setAttribute('suitability', PackageSuitability::check($p, $birth, $gender)));
        }

        return PackageResource::collection($packages);
    }

    /** Submit a registration request. Returns the request number. */
    public function store(StoreRegistrationRequest $request, RegistrationService $service): JsonResponse
    {
        $package = Package::findOrFail($request->validated('package_id'));
        $req = $service->submit($package, $request->validated(), $request->file('photo'));

        return response()->json([
            'message' => __('registration.submitted'),
            'request_no' => $req->request_no,
            'status' => $req->status->value,
            'waitlist_position' => $req->waitlist_position,
            'track_url' => config('ahl.frontend_url').'/track/'.$req->request_no,
        ], 201);
    }

    /** Track a request by number. The guardian phone must match. */
    public function track(Request $request, string $requestNo): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string']]);
        $phone = PhoneNumber::normalize($data['phone']);

        $req = RegistrationRequest::with('package')->where('request_no', strtoupper($requestNo))->first();

        if (! $req || ! $phone || ($req->guardian_phone !== $phone && $req->student_phone !== $phone)) {
            return response()->json(['message' => __('registration.errors.not_found')], 404);
        }

        return response()->json(['data' => new RegistrationRequestResource($req)]);
    }
}
