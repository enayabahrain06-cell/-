<?php

namespace App\Http\Resources;

use App\Services\Certificates\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $service = app(CertificateService::class);
        $locale = app()->getLocale();
        $approved = $this->isApproved();
        $verifyUrl = $approved ? $service->verifyUrl($this->resource) : null;
        $canOpen = $user?->can('view', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'certificate_no' => $this->certificate_no,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'title' => $this->title,
            'achievement' => $this->achievement,
            'grade' => $this->grade?->value,
            'grade_label' => $this->grade?->label(),
            'source' => $this->source?->value,
            'source_label' => $this->source?->label(),
            'student_id' => $this->student_id,
            'student' => $this->whenLoaded('student', fn () => new StudentSummaryResource($this->student)),
            'exam_id' => $this->exam_id,
            'lesson_id' => $this->lesson_id,
            'lesson' => $this->whenLoaded('lesson', fn () => $this->lesson ? ['id' => $this->lesson->id, 'name' => $this->lesson->name] : null),
            'issued_on' => $this->issued_on?->toDateString(),
            'issued_on_hijri' => $this->issued_on ? (hijri_date($this->issued_on, $locale === 'en' ? 'en' : 'ar') ?: null) : null,
            'issued_by' => $this->whenLoaded('issuer', fn () => $this->issuer?->name),
            'approved_at' => display_tz($this->approved_at)?->toIso8601String(),
            'approved_by' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'revoked_at' => display_tz($this->revoked_at)?->toIso8601String(),
            'revoke_reason' => $this->when($user?->can('certificates.view'), $this->revoke_reason),
            'sent_at' => display_tz($this->sent_at)?->toIso8601String(),
            'print_count' => (int) $this->print_count,
            'verify_url' => $verifyUrl,
            'pdf_url' => $canOpen ? route('certificates.pdf', $this->resource) : null,
            // Short-lived signed links: open without a session (new tab, WhatsApp share, mobile download).
            'download_url' => $canOpen ? $service->downloadUrl($this->resource) : null,
            'view_url' => $canOpen ? $service->downloadUrl($this->resource, false, true) : null,
            'print_url' => $approved && $canOpen ? $service->downloadUrl($this->resource, true) : null,
            'whatsapp_share_url' => $verifyUrl ? 'https://wa.me/?text='.rawurlencode($this->title.' — '.$verifyUrl) : null,
            'can' => $user ? [
                'update' => $this->isDraft() && $user->can('update', $this->resource),
                'approve' => $this->isDraft() && $user->can('approve', $this->resource),
                'revoke' => $approved && $user->can('revoke', $this->resource),
                'send' => $approved && $user->can('send', $this->resource),
            ] : null,
        ];
    }
}
