<?php

namespace App\Http\Controllers\Api\Lessons;

use App\Enums\BookingSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\StoreLocationBookingRequest;
use App\Http\Resources\LocationBookingResource;
use App\Models\Location;
use App\Models\LocationBooking;
use App\Services\Lessons\LocationConflictDetector;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * @group Lessons & locations
 */
class LocationBookingController extends Controller
{
    public function __construct(private LocationConflictDetector $detector) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Location::class);

        $bookings = LocationBooking::with('location')
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('booking_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('booking_date', '<=', $request->string('to')))
            ->orderBy('booking_date')->orderBy('start_time')
            ->paginate((int) $request->integer('per_page', 50));

        return LocationBookingResource::collection($bookings);
    }

    public function store(StoreLocationBookingRequest $request): LocationBookingResource
    {
        $data = $request->validated();
        $this->assertFree($data);

        $booking = LocationBooking::create($data + ['source' => $data['source'] ?? BookingSource::Manual->value, 'created_by' => $request->user()->id]);

        return new LocationBookingResource($booking->load('location'));
    }

    public function update(StoreLocationBookingRequest $request, LocationBooking $booking): LocationBookingResource
    {
        $data = array_merge($booking->only(['location_id', 'booking_date', 'start_time', 'end_time']), $request->validated());
        $data['booking_date'] = Carbon::parse($data['booking_date'])->toDateString();
        $this->assertFree($data, $booking->id);

        $booking->update($request->validated());

        return new LocationBookingResource($booking->fresh()->load('location'));
    }

    public function destroy(LocationBooking $booking): JsonResponse
    {
        $this->authorize('delete', $booking->location);
        $booking->delete();

        return response()->json(['message' => __('api.deleted')]);
    }

    private function assertFree(array $data, ?int $ignoreId = null): void
    {
        $conflicts = $this->detector->forDate((int) $data['location_id'], Carbon::parse($data['booking_date']), $data['start_time'], $data['end_time'], null, $ignoreId);

        if ($conflicts) {
            throw ValidationException::withMessages(['start_time' => __('lessons.slot_busy')])->status(422);
        }
    }
}
