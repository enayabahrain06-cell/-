<?php

namespace App\Http\Controllers\Api\Audit;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Audit log viewer: who changed what and when, with the old and new values.
 * A viewer limited to one gender track only sees entries made by staff of that track.
 *
 * @group Audit
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('audit.view'), 403);
        $f = $request->validate([
            'action' => ['nullable', 'string', 'max:60'], 'user_id' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:60'], 'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');

        $page = AuditLog::with('user:id,name')
            ->tap(fn (Builder $q) => $this->trackScope($q, $request->user()))
            // "wallet" matches wallet.adjusted and wallet.refunded; a full name matches exactly.
            ->when($f['action'] ?? null, fn ($q, $a) => str_contains($a, '.') ? $q->where('action', $a) : $q->where('action', 'like', $a.'.%'))
            ->when($f['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($f['subject'] ?? null, fn ($q, $s) => $q->where('auditable_type', 'like', '%'.class_basename($s)))
            ->when($f['subject_id'] ?? null, fn ($q, $id) => $q->where('auditable_id', $id))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, $tz)->startOfDay()->utc()))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d, $tz)->endOfDay()->utc()))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($f['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'action_label' => __('audit.actions.'.str_replace('.', '_', $l->action)) === 'audit.actions.'.str_replace('.', '_', $l->action) ? $l->action : __('audit.actions.'.str_replace('.', '_', $l->action)),
                'subject' => class_basename($l->auditable_type),
                'subject_id' => $l->auditable_id,
                'user' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name] : null,
                'old_values' => $l->old_values ?? [],
                'new_values' => $l->new_values ?? [],
                'ip' => $l->ip,
                'created_at' => display_tz($l->created_at)?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Filter options: action groups present in the log and the staff who made entries. */
    public function options(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('audit.view'), 403);
        $base = AuditLog::query()->tap(fn (Builder $q) => $this->trackScope($q, $request->user()));
        $actions = (clone $base)->distinct()->orderBy('action')->pluck('action');

        return response()->json(['data' => [
            'groups' => $actions->map(fn ($a) => explode('.', $a)[0])->unique()->values()
                ->map(fn ($g) => ['value' => $g, 'label' => __("audit.groups.{$g}") === "audit.groups.{$g}" ? $g : __("audit.groups.{$g}")]),
            'actions' => $actions,
            'users' => User::whereIn('id', (clone $base)->whereNotNull('user_id')->distinct()->select('user_id'))->orderBy('name')->get(['id', 'name']),
        ]]);
    }

    private function trackScope(Builder $q, User $viewer): void
    {
        $limit = Track::genderFor($viewer);
        if ($limit) {
            $q->whereIn('user_id', User::where(fn ($u) => $u->where('track', $limit->value)->orWhere('id', $viewer->id))->select('id'));
        }
    }
}
