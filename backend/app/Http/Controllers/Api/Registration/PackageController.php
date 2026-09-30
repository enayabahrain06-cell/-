<?php

namespace App\Http\Controllers\Api\Registration;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\PackageRequest;
use App\Http\Resources\PackageResource;
use App\Models\Package;
use App\Services\AuditLogger;
use App\Support\TermScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Packages & registration
 */
class PackageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Package::class);

        $q = Package::with('academicTerm')->withCount([
            'registrationRequests as accepted_count' => fn ($q) => $q->whereIn('status', RegistrationStatus::seated()),
            'registrationRequests as pending_count' => fn ($q) => $q->where('status', 'pending'),
            'registrationRequests as waitlist_count' => fn ($q) => $q->where('status', 'waitlist'),
            'lessons',
        ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->string('gender')))
            ->tap(fn ($q) => \App\Support\Track::scope($q, $request->user()))
            ->tap(fn ($q) => TermScope::packages($q, TermScope::fromRequest($request)))
            ->orderByDesc('start_date');

        return PackageResource::collection($q->paginate((int) $request->integer('per_page', 25)));
    }

    public function store(PackageRequest $request, AuditLogger $audit): JsonResponse
    {
        $this->authorize('create', Package::class);

        // A new package joins the current term unless another one was chosen.
        $package = Package::create($request->validated() + ['academic_term_id' => TermScope::defaultId()]);
        $audit->record('package.created', $package, [], $package->only(['name', 'price_fils', 'seats', 'status']));

        return (new PackageResource($package))->response()->setStatusCode(201);
    }

    public function show(Package $package): PackageResource
    {
        $this->authorize('view', $package);

        $package->loadCount([
            'registrationRequests as accepted_count' => fn ($q) => $q->whereIn('status', RegistrationStatus::seated()),
            'registrationRequests as pending_count' => fn ($q) => $q->where('status', 'pending'),
            'registrationRequests as waitlist_count' => fn ($q) => $q->where('status', 'waitlist'),
            'lessons',
        ]);

        return new PackageResource($package->load('academicTerm'));
    }

    public function update(PackageRequest $request, Package $package, AuditLogger $audit): PackageResource
    {
        $this->authorize('update', $package);

        $old = $package->only(['name', 'price_fils', 'seats', 'status', 'min_age', 'max_age', 'gender', 'academic_term_id']);
        $package->update($request->validated());
        $audit->record('package.updated', $package, $old, $package->only(array_keys($old)));

        return new PackageResource($package->fresh());
    }

    public function destroy(Package $package): JsonResponse
    {
        $this->authorize('delete', $package);

        if ($package->registrationRequests()->exists() || $package->lessons()->exists()) {
            return response()->json(['message' => __('registration.errors.package_in_use')], 422);
        }
        $package->delete();

        return response()->json(['message' => __('api.deleted')]);
    }
}
