<?php

namespace App\Http\Controllers\Api\Activities;

use App\Models\Activity;
use App\Models\ActivityBookDelivery;
use App\Models\ActivityRegistration;
use App\Models\Invoice;
use App\Services\Activities\ActivityRegistrar;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletService;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @group Activities
 *
 * تسليم كتاب البرنامج (activities.manage): the program's book handed to registered students, in bulk. The book
 * price is billed as the registration's book invoice at delivery (unless it was billed at registration).
 * متابعة تسليم كتاب البرنامج reads the roster.
 */
class ActivityBookController extends ActivityBase
{
    public function __construct(private AuditLogger $audit, private ActivityRegistrar $registrar) {}

    public function deliver(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($request->user()->can('activities.manage'), 403);
        $this->reach($request, $activity);
        if (! $activity->has_book) {
            throw ValidationException::withMessages(['activity' => __('activities.errors.no_book')]);
        }
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:300'],
            'student_ids.*' => ['integer'],
            'charge' => ['boolean'],
            'delivered_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $charge = (bool) ($data['charge'] ?? true);
        $already = ActivityBookDelivery::where('activity_id', $activity->id)->pluck('student_id')->all();
        $regs = ActivityRegistration::with('student')->where('activity_id', $activity->id)->where('status', 'registered')
            ->whereIn('student_id', array_diff($data['student_ids'], $already))->get()
            ->filter(fn ($r) => Track::allows($request->user(), $r->student->gender));
        $on = Carbon::parse($data['delivered_at'] ?? today());

        DB::transaction(function () use ($regs, $activity, $charge, $on, $data, $request) {
            foreach ($regs as $r) {
                $invoice = $charge ? $this->registrar->billBook($activity, $r, $r->student, $request->user(), $on) : null;
                $d = ActivityBookDelivery::create(['activity_id' => $activity->id, 'student_id' => $r->student_id, 'delivered_at' => $on->toDateString(),
                    'delivered_by' => $request->user()->id, 'notes' => $data['notes'] ?? null]);
                $this->audit->record('activity.book_delivered', $d, [], ['activity_id' => $activity->id, 'student_id' => $r->student_id, 'invoice_id' => $invoice?->id]);
            }
        });

        return response()->json(['message' => __('activities.book_delivered', ['count' => $regs->count()]), 'delivered' => $regs->count()]);
    }

    /** Undo a delivery; the unpaid book invoice is cancelled, refused once anything was paid on it. */
    public function undo(Request $request, Activity $activity, ActivityBookDelivery $delivery, WalletService $wallets): JsonResponse
    {
        abort_unless($request->user()->can('activities.manage'), 403);
        $this->reach($request, $activity);
        abort_unless($delivery->activity_id === $activity->id, 404);
        $reg = ActivityRegistration::where('activity_id', $activity->id)->where('student_id', $delivery->student_id)->first();
        $invoice = $reg?->book_invoice_id ? Invoice::find($reg->book_invoice_id) : null;
        if ($invoice && $invoice->paid_fils > 0) {
            throw ValidationException::withMessages(['delivery' => __('activities.errors.book_paid')]);
        }
        DB::transaction(function () use ($delivery, $invoice, $wallets, $activity, $reg) {
            if ($invoice) {
                $wallets->cancelInvoice($invoice, __('activities.book_undo_note', ['name' => $activity->name_ar], 'ar'));
                $reg->update(['book_invoice_id' => null]);
            }
            $this->audit->record('activity.book_undone', $delivery, $delivery->only(['activity_id', 'student_id']), []);
            $delivery->delete();
        });

        return response()->json(['message' => __('activities.book_undone')]);
    }
}
