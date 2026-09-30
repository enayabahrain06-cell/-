<?php

namespace App\Http\Controllers\Api\Activities;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Invoice;
use App\Support\Track;
use Illuminate\Http\Request;

/**
 * Shared by the البرامج / الرحلات controllers: one engine for both types. Permission checks and JSON shapes.
 * activities.view reads; activities.manage edits programs/trips and hands books; activities.register registers;
 * activities.attendance records attendance; activities.evaluate evaluates. Fee screens use payments.record and
 * wallets.view, so their users reach the lists too.
 */
abstract class ActivityBase extends Controller
{
    protected const SEE = ['activities.view', 'activities.manage', 'activities.register', 'activities.attendance', 'activities.evaluate', 'payments.record', 'wallets.view'];

    protected function authorizeSee(Request $request): void
    {
        $user = $request->user();
        abort_unless(collect(self::SEE)->contains(fn ($p) => $user->can($p)), 403);
    }

    protected function authorizeAny(Request $request, string ...$permissions): void
    {
        $user = $request->user();
        abort_unless(collect($permissions)->contains(fn ($p) => $user->can($p)), 403);
    }

    /** The activity must be one this user's gender track may see. */
    protected function reach(Request $request, Activity $activity): void
    {
        abort_unless(Track::allows($request->user(), $activity->gender), 404);
    }

    protected static function canMoney(Request $request): bool
    {
        return $request->user()->can('wallets.view') || $request->user()->can('payments.record');
    }

    protected static function activity(Activity $a): array
    {
        return [
            'id' => $a->id, 'type' => $a->type, 'academic_term_id' => $a->academic_term_id,
            'name' => $a->name(), 'name_ar' => $a->name_ar, 'name_en' => $a->name_en, 'description' => $a->description,
            'starts_on' => $a->starts_on?->toDateString(), 'ends_on' => $a->ends_on?->toDateString(),
            'start_time' => $a->start_time, 'end_time' => $a->end_time,
            'location' => $a->location ? ['id' => $a->location->id, 'name' => $a->location->name] : null,
            'place' => $a->place, 'seats' => $a->seats, 'price_fils' => $a->price_fils,
            'gender' => $a->gender, 'min_age' => $a->min_age, 'max_age' => $a->max_age,
            'level' => $a->level ? ['id' => $a->level->id, 'name' => $a->level->name()] : null,
            'has_book' => $a->has_book, 'book_title' => $a->book_title, 'book_price_fils' => $a->book_price_fils,
            'status' => $a->status,
            'registered_count' => (int) ($a->registered_count ?? 0), 'waitlist_count' => (int) ($a->waitlist_count ?? 0),
        ];
    }

    protected static function invoice(?Invoice $i): ?array
    {
        return $i ? ['id' => $i->id, 'invoice_no' => $i->invoice_no, 'amount_fils' => $i->amount_fils, 'paid_fils' => $i->paid_fils,
            'remaining_fils' => $i->status->value === 'cancelled' ? 0 : $i->outstandingFils(), 'status' => $i->status->value, 'due_date' => $i->due_date?->toDateString()] : null;
    }

    /** Activities of a term, with their registered and waiting-list counts. */
    protected static function withCounts(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->with(['location', 'level'])->withCount([
            'registrations as registered_count' => fn ($r) => $r->where('status', 'registered'),
            'registrations as waitlist_count' => fn ($r) => $r->where('status', 'waitlist'),
        ]);
    }
}
