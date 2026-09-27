<?php

namespace App\Http\Controllers\Api\Enrollment;

use App\Enums\Gender;
use App\Enums\PackageStatus;
use App\Exports\EnrollmentTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollment\QuickEnrollRequest;
use App\Models\Package;
use App\Services\Enrollment\EnrollmentImport;
use App\Services\Enrollment\QuickEnrollmentService;
use App\Support\PhoneNumber;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @group Quick enrollment
 *
 * Staff enroll a student straight into a circle (spec section 15), singly or from an Excel sheet.
 */
class QuickEnrollmentController extends Controller
{
    public function __construct(private QuickEnrollmentService $service, private EnrollmentImport $import) {}

    /** Packages suitable for this age and gender, each with the circles the user may fill. */
    public function options(Request $request): JsonResponse
    {
        $this->authorizeQuick($request);
        $data = $request->validate(['birth_date' => ['nullable', 'date'], 'gender' => ['nullable', Gender::rule()]]);

        $packages = $this->service->options(
            $request->user(),
            ! empty($data['birth_date']) ? Carbon::parse($data['birth_date']) : null,
            ! empty($data['gender']) ? Gender::from($data['gender']) : null,
            app()->getLocale(),
        );

        return response()->json([
            'data' => $packages,
            'can_record_payment' => $request->user()->can('payments.record'),
            'memorization_levels' => \App\Enums\MemorizationLevel::options(app()->getLocale()),
        ]);
    }

    /** Guardian and siblings by phone, and duplicate warnings, while the form is being filled. */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorizeQuick($request);
        $data = $request->validate(['guardian_phone' => ['nullable', 'string', 'max:30'], 'full_name' => ['nullable', 'string', 'max:150'], 'birth_date' => ['nullable', 'date']]);
        $phone = filled($data['guardian_phone'] ?? null) ? PhoneNumber::normalize($data['guardian_phone']) : null;

        return response()->json($this->service->lookup($request->user(), $phone, $data['full_name'] ?? null, $data['birth_date'] ?? null));
    }

    /** Save and enroll (or add to the waitlist when the package is full and waitlist=1). */
    public function store(QuickEnrollRequest $request): JsonResponse
    {
        $result = $this->service->enroll($request->user(), $request->validated(), $request->file('photo'));

        return response()->json([
            'status' => $result['status'],
            'message' => __('enrollment.done_'.$result['status']),
            'request_no' => $result['request']->request_no,
            'waitlist_position' => $result['request']->waitlist_position,
            'student' => $result['student'] ? [
                'id' => $result['student']->id,
                'student_no' => $result['student']->student_no,
                'full_name' => $result['student']->full_name,
            ] : null,
            'payment' => $result['payment'] ? ['id' => $result['payment']->id, 'receipt_no' => $result['payment']->receipt_no, 'amount_fils' => $result['payment']->amount_fils] : null,
        ], 201);
    }

    public function template(Request $request): BinaryFileResponse
    {
        $this->authorizeQuick($request);
        $user = $request->user();
        $circles = Track::scope(Package::query(), $user)->where('status', PackageStatus::Open->value)->get()
            ->flatMap(fn (Package $p) => $this->service->eligibleCircles($user, $p)->map(fn ($c) => $c + ['package' => $p->localizedName(app()->getLocale())]))
            ->values()->all();

        return Excel::download(new EnrollmentTemplateExport($circles), 'quick-enrollment-template.xlsx');
    }

    /** Validate an uploaded sheet without writing anything. */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeQuick($request);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']]);
        $rows = $this->import->preview($request->user(), $request->file('file'));

        return response()->json([
            'rows' => $rows,
            'valid' => count(array_filter($rows, fn ($r) => ! $r['errors'] && ! $r['warnings'])),
            'warnings' => count(array_filter($rows, fn ($r) => ! $r['errors'] && $r['warnings'])),
            'invalid' => count(array_filter($rows, fn ($r) => (bool) $r['errors'])),
        ]);
    }

    /** Enroll the previewed rows that are (still) valid. */
    public function commit(Request $request): JsonResponse
    {
        $this->authorizeQuick($request);
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.row' => ['required', 'integer'],
            'rows.*.data' => ['required', 'array'],
            'include_warnings' => ['boolean'],
        ]);

        $result = $this->import->commit($request->user(), $data['rows'], (bool) ($data['include_warnings'] ?? false));

        return response()->json($result + ['message' => __('enrollment.import_done', ['count' => count($result['enrolled'])])]);
    }

    private function authorizeQuick(Request $request): void
    {
        abort_unless($request->user()?->can('enrollment.quick'), 403);
    }
}
