<?php

namespace Ahl\Certificates\Http\Controllers;

use Ahl\Certificates\Certificates;
use Ahl\Certificates\Enums\CertificateStatus;
use Ahl\Certificates\Http\Resources\CertificateResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Certificates
 */
class RecipientCertificateController extends Controller
{
    use AuthorizesRequests;

    /**
     * One recipient's certificates, newest first, with a summary (approved total, approved per type, drafts, latest).
     * Staff (manageRecipient) see drafts; everyone else gets approved and revoked ones read-only.
     */
    public function index(Request $request, string $type, string $id): JsonResponse
    {
        $class = Certificates::recipientClass($type);
        abort_unless($class, 404);
        $recipient = $class::query()->findOrFail($id);
        $model = Certificates::model();
        $this->authorize('viewRecipient', [$model, $recipient]);
        $user = $request->user();
        $staff = $user->can('manageRecipient', [$model, $recipient]);

        $certificates = $model::query()->forRecipient($recipient)->with(['context', 'approver'])
            ->when(! $staff, fn ($q) => $q->where('status', '!=', CertificateStatus::Draft->value))
            ->orderByDesc('issued_on')->orderByDesc('id')->get()
            ->each(fn ($c) => $c->setRelation('recipient', $recipient));

        $approved = $certificates->where('status', CertificateStatus::Approved);
        $latest = $approved->first();

        return response()->json([
            'data' => CertificateResource::collection($certificates),
            'recipient' => Certificates::host()->presentRecipient($recipient),
            'summary' => [
                'total' => $approved->count(),
                'by_type' => collect(Certificates::keys('types'))->mapWithKeys(fn ($t) => [$t => $approved->where('type', $t)->count()]),
                'drafts' => $staff ? $certificates->where('status', CertificateStatus::Draft)->count() : null,
                'latest' => $latest ? ['id' => $latest->id, 'title' => $latest->title, 'achievement' => $latest->achievement, 'issued_on' => $latest->issued_on?->toDateString()] : null,
            ],
            'meta' => [
                'read_only' => ! $staff,
                'can_issue' => $user->can('issueFor', [$model, $recipient]),
            ],
        ]);
    }
}
