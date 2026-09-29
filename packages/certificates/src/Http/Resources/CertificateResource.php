<?php

namespace Ahl\Certificates\Http\Resources;

use Ahl\Certificates\Certificates;
use Ahl\Certificates\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Ahl\Certificates\Models\Certificate */
class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $service = app(CertificateService::class);
        $host = Certificates::host();
        $locale = app()->getLocale();
        $approved = $this->isApproved();
        $verifyUrl = $approved ? $service->verifyUrl($this->resource) : null;
        $canOpen = $user?->can('view', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'certificate_no' => $this->certificate_no,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'title' => $this->title,
            'achievement' => $this->achievement,
            'grade' => $this->grade,
            'grade_label' => $this->gradeLabel(),
            'source' => $this->source,
            'source_label' => $this->sourceLabel(),
            'source_id' => $this->source_id,
            'recipient_type' => $this->recipient_type ? Certificates::recipientAlias($this->recipientClass()) : null,
            'recipient_id' => $this->recipient_id,
            'recipient' => $this->whenLoaded('recipient', fn () => $this->recipient ? $host->presentRecipient($this->recipient) : null),
            'context' => $this->whenLoaded('context', fn () => $this->context ? $host->presentContext($this->context) : null),
            'issued_on' => $this->issued_on?->toDateString(),
            'issued_on_secondary' => $this->issued_on ? $host->secondaryDate($this->issued_on, $locale) : null,
            'issued_by' => $this->whenLoaded('issuer', fn () => $this->issuer?->name),
            'approved_at' => $host->displayTime($this->approved_at)?->toIso8601String(),
            'approved_by' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'revoked_at' => $host->displayTime($this->revoked_at)?->toIso8601String(),
            'revoke_reason' => $this->when((bool) $user?->can('viewAny', $this->resource::class), $this->revoke_reason),
            'sent_at' => $host->displayTime($this->sent_at)?->toIso8601String(),
            'print_count' => (int) $this->print_count,
            'verify_url' => $verifyUrl,
            'pdf_url' => $canOpen ? route('certificates.pdf', $this->resource) : null,
            // Short-lived signed links: open without a session (new tab, share, mobile download).
            'download_url' => $canOpen ? $service->downloadUrl($this->resource) : null,
            'view_url' => $canOpen ? $service->downloadUrl($this->resource, false, true) : null,
            'print_url' => $approved && $canOpen ? $service->downloadUrl($this->resource, true) : null,
            'whatsapp_share_url' => $verifyUrl ? 'https://wa.me/?text='.rawurlencode($this->title.' — '.$verifyUrl) : null,
            'can' => $user ? [
                'update' => $this->isDraft() && $user->can('update', $this->resource),
                'approve' => $this->isDraft() && $user->can('approve', $this->resource),
                'revoke' => $approved && $user->can('revoke', $this->resource),
                'send' => $approved && $service->canSend() && $user->can('send', $this->resource),
            ] : null,
        ];
    }

    /** The model class behind recipient_type (resolving a morph map alias). */
    private function recipientClass(): string
    {
        return \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($this->recipient_type) ?? $this->recipient_type;
    }
}
