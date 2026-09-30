<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Enums\InvoiceStatus;
use App\Enums\LessonStudentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentSummaryResource;
use App\Models\Invoice;
use App\Models\LessonStudent;
use App\Services\Dashboard\FeesPanel;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @group Wallet
 *
 * متابعة الدفع: every student with an active class in the selected term and their invoices of that term
 * (academic_term_id): total, paid, remaining and a status. Reads existing invoices only; no money data of its own.
 */
class PaymentFollowupController extends Controller
{
    public function index(Request $request, FeesPanel $panel): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('wallets.view'), 403);
        $term = TermScope::single($request);
        $data = $request->validate([
            'lesson_id' => ['nullable', 'integer'],
            'level_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['paid', 'partial', 'unpaid', 'none'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $rows = LessonStudent::with(['student', 'lesson.level'])
            ->where('status', LessonStudentStatus::Active->value)
            ->whereHas('lesson', fn ($q) => TermScope::via($q, $term->id)
                ->when($data['lesson_id'] ?? null, fn ($x, $id) => $x->whereKey($id))
                ->when($data['level_id'] ?? null, fn ($x, $id) => $x->where('level_id', $id)))
            ->whereHas('student', fn ($q) => Track::scope($q, $user)
                ->when($data['search'] ?? null, fn ($x, $s) => $x->where(fn ($w) => $w->where('full_name', 'like', "%{$s}%")->orWhere('student_no', 'like', "%{$s}%")->orWhere('guardian_phone', 'like', "%{$s}%"))))
            ->get()->unique('student_id');

        $today = today();
        $invoices = Invoice::where('academic_term_id', $term->id)->whereIn('student_id', $rows->pluck('student_id'))
            ->where('status', '!=', InvoiceStatus::Cancelled->value)->orderBy('due_date')->get()->groupBy('student_id');

        $list = $rows->map(function (LessonStudent $r) use ($invoices, $today, $request) {
            $mine = $invoices->get($r->student_id, collect());
            $total = (int) $mine->sum('amount_fils');
            $paid = (int) $mine->sum('paid_fils');
            $remaining = max(0, $total - $paid);
            $status = match (true) {
                $mine->isEmpty() => 'none',
                $remaining === 0 => 'paid',
                $paid > 0 => 'partial',
                default => 'unpaid',
            };

            return [
                'student' => (new StudentSummaryResource($r->student))->toArray($request),
                'lesson' => ['id' => $r->lesson->id, 'name' => $r->lesson->name],
                'level' => $r->lesson->level ? ['id' => $r->lesson->level->id, 'name' => $r->lesson->level->name()] : null,
                'invoices' => $mine->map(fn (Invoice $i) => ['id' => $i->id, 'invoice_no' => $i->invoice_no, 'description' => $i->description, 'amount_fils' => $i->amount_fils,
                    'paid_fils' => $i->paid_fils, 'due_date' => $i->due_date?->toDateString(), 'status' => $i->status->value])->values(),
                'total_fils' => $total,
                'paid_fils' => $paid,
                'remaining_fils' => $remaining,
                'status' => $status,
                'overdue' => $mine->contains(fn (Invoice $i) => $i->outstandingFils() > 0 && $i->due_date?->lt($today)),
            ];
        })->when($data['status'] ?? null, fn ($c, $s) => $c->where('status', $s))
            ->sortBy(fn ($r) => [$r['lesson']['name'], $r['student']['full_name']])->values();

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'data' => $list,
            'totals' => [
                'students' => $list->count(),
                'total_fils' => $list->sum('total_fils'),
                'paid_fils' => $list->sum('paid_fils'),
                'remaining_fils' => $list->sum('remaining_fils'),
                'by_status' => collect(['paid', 'partial', 'unpaid', 'none'])->mapWithKeys(fn ($s) => [$s => $list->where('status', $s)->count()]),
            ],
            'can_record' => $user->can('payments.record'),
            'can_remind' => $user->can('dashboard.view') && $panel->canRemind($user),
        ]);
    }
}
