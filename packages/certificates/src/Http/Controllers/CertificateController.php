<?php

namespace Ahl\Certificates\Http\Controllers;

use Ahl\Certificates\Certificates;
use Ahl\Certificates\CertificateService;
use Ahl\Certificates\Enums\CertificateStatus;
use Ahl\Certificates\Http\Resources\CertificateResource;
use Ahl\Certificates\Models\Certificate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @group Certificates
 *
 * Draft → approved → revoked, for any recipient model.
 */
class CertificateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private CertificateService $certificates) {}

    /** Lists for the filters and the issue form. */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificates::model());

        return response()->json(['data' => [
            'types' => Certificates::options('types'),
            'statuses' => CertificateStatus::options(),
            'grades' => Certificates::options('grades'),
            'sources' => Certificates::options('sources'),
            'recipient_types' => array_keys(config('certificates.recipients', [])),
            'context_types' => array_keys(config('certificates.contexts', [])),
            'locales' => Certificates::locales(),
            'placeholders' => $this->certificates->placeholderKeys(),
            'require_approval' => (bool) Certificates::setting('require_approval', true),
            'can_send' => $this->certificates->canSend(),
        ]]);
    }

    /**
     * Certificates the user may see, newest first.
     * Filters: status, type, source, recipient_type + recipient_id, context_type + context_id, search, from, to.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificates::model());

        $q = Certificates::model()::query()->with(['recipient', 'context', 'issuer', 'approver'])
            ->tap(fn (Builder $q) => Certificates::host()->scopeVisible($q, $request->user()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('recipient_id') && ($class = Certificates::recipientClass($request->input('recipient_type'))),
                fn ($q) => $q->where('recipient_type', Certificates::morphClass($class))->where('recipient_id', $request->input('recipient_id')))
            ->when($request->filled('context_id') && ($class = Certificates::contextClass($request->input('context_type'))),
                fn ($q) => $q->where('context_type', Certificates::morphClass($class))->where('context_id', $request->input('context_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('issued_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('issued_on', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = '%'.$request->string('search').'%';
                $q->where(function (Builder $w) use ($like) {
                    $w->where('certificate_no', 'like', $like)->orWhere('title', 'like', $like)->orWhere('achievement', 'like', $like);
                    Certificates::host()->searchRecipients($w, $like);
                });
            })
            ->orderByDesc('issued_on')->orderByDesc('id');

        $counts = (clone $q)->reorder()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return CertificateResource::collection($q->paginate(min(100, max(1, (int) $request->integer('per_page', 25)))))
            ->additional(['meta' => ['status_counts' => collect(CertificateStatus::values())->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])]])
            ->response();
    }

    /** Create drafts for one or more recipients (same type, achievement and grade). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_type' => ['nullable', 'string', Rule::in(array_keys(config('certificates.recipients', [])))],
            'recipient_ids' => ['required', 'array', 'min:1', 'max:200'],
            'recipient_ids.*' => ['distinct'],
            'type' => ['required', Rule::in(Certificates::keys('types'))],
            'achievement' => ['required', 'string', 'min:2', 'max:255'],
            'title' => ['nullable', 'string', 'max:200'],
            'grade' => ['nullable', Rule::in(Certificates::keys('grades'))],
            'context_type' => ['nullable', 'required_with:context_id', Rule::in(array_keys(config('certificates.contexts', [])))],
            'context_id' => ['nullable'],
        ]);

        $class = Certificates::recipientClass($data['recipient_type'] ?? null);
        abort_unless($class, 422, __('certificates::certificates.errors.no_recipients'));
        $recipients = Certificates::host()->resolveRecipients($class, $data['recipient_ids']);
        if ($recipients->count() !== count($data['recipient_ids'])) {
            return response()->json(['message' => __('certificates::certificates.errors.recipient_missing'), 'errors' => ['recipient_ids' => [__('certificates::certificates.errors.recipient_missing')]]], 422);
        }
        foreach ($recipients as $recipient) {
            $this->authorize('issueFor', [Certificates::model(), $recipient]);
        }
        $data['context'] = $this->context($data['context_type'] ?? null, $data['context_id'] ?? null);

        $created = DB::transaction(fn () => $recipients->map(fn (Model $r) => $this->certificates->createDraft($r, $data['type'], $data, $request->user())));

        return response()->json([
            'message' => trans_choice('certificates::certificates.messages.created', $created->count(), ['count' => $created->count()]),
            'data' => CertificateResource::collection(Certificates::model()::with('recipient', 'context')->whereIn('id', $created->pluck('id'))->get()),
        ], 201);
    }

    public function show(Certificate $certificate): CertificateResource
    {
        $this->authorize('view', $certificate);

        return new CertificateResource($certificate->load(['recipient', 'context', 'issuer', 'approver']));
    }

    /** Edit a draft (achievement, title, grade, context, date). */
    public function update(Request $request, Certificate $certificate): JsonResponse
    {
        $this->authorize('update', $certificate);
        $data = $request->validate([
            'achievement' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'grade' => ['sometimes', 'nullable', Rule::in(Certificates::keys('grades'))],
            'context_type' => ['sometimes', 'nullable', Rule::in(array_keys(config('certificates.contexts', [])))],
            'context_id' => ['sometimes', 'nullable'],
            'issued_on' => ['sometimes', 'date'],
        ]);
        if (array_key_exists('context_id', $data)) {
            $data['context'] = $this->context($data['context_type'] ?? null, $data['context_id']);
        }

        $this->certificates->update($certificate, $data);

        return response()->json(['message' => __('certificates::certificates.messages.updated'), 'data' => new CertificateResource($certificate->fresh(['recipient', 'context']))]);
    }

    public function destroy(Certificate $certificate): JsonResponse
    {
        $this->authorize('delete', $certificate);
        $this->certificates->delete($certificate);

        return response()->json(['message' => __('certificates::certificates.messages.deleted')]);
    }

    public function approve(Request $request, Certificate $certificate): JsonResponse
    {
        $this->authorize('approve', $certificate);
        $this->certificates->approve($certificate, $request->user());

        return response()->json(['message' => trans_choice('certificates::certificates.messages.approved', 1, ['count' => 1]), 'data' => new CertificateResource($certificate->fresh(['recipient', 'context']))]);
    }

    /** Approve several drafts at once; ones the user may not approve are skipped. */
    public function approveMany(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);
        $user = $request->user();

        $approved = Certificates::model()::with('recipient')->whereIn('id', $data['ids'])->where('status', CertificateStatus::Draft->value)->get()
            ->filter(fn (Certificate $c) => $user->can('approve', $c))
            ->each(fn (Certificate $c) => $this->certificates->approve($c, $user));

        return response()->json(['message' => trans_choice('certificates::certificates.messages.approved', max(1, $approved->count()), ['count' => $approved->count()]), 'approved' => $approved->count()]);
    }

    public function revoke(Request $request, Certificate $certificate): JsonResponse
    {
        $this->authorize('revoke', $certificate);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->certificates->revoke($certificate, $request->user(), $data['reason']);

        return response()->json(['message' => __('certificates::certificates.messages.revoked'), 'data' => new CertificateResource($certificate->fresh(['recipient', 'context']))]);
    }

    /** Notify the recipient again (verification link included). */
    public function send(Certificate $certificate): JsonResponse
    {
        $this->authorize('send', $certificate);
        abort_unless($this->certificates->canSend(), 403);
        $sent = $this->certificates->send($certificate);

        return response()->json(['message' => __('certificates::certificates.messages.sent'), 'sent' => $sent]);
    }

    /** Stream the PDF for a signed-in user. */
    public function pdf(Certificate $certificate): Response
    {
        $this->authorize('view', $certificate);

        return $this->stream($certificate);
    }

    /**
     * Signed temporary download (link from the API, valid link_minutes).
     * `view=1` opens inline (previews); `print=1` opens inline and counts a print on approved certificates.
     *
     * @unauthenticated
     */
    public function download(Request $request, Certificate $certificate): Response
    {
        if ($request->boolean('print') && $certificate->isApproved()) {
            $certificate->increment('print_count');
        }

        return $this->stream($certificate, $request->boolean('print') || $request->boolean('view') ? 'inline' : 'attachment');
    }

    private function stream(Certificate $certificate, string $disposition = 'inline'): Response
    {
        return response($this->certificates->pdfContents($certificate), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$certificate->certificate_no.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function context(?string $alias, mixed $id): ?Model
    {
        if ($id === null || $id === '') {
            return null;
        }
        $class = Certificates::contextClass($alias);
        abort_unless($class, 422);

        return $class::query()->findOrFail($id);
    }
}
