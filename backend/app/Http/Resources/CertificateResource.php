<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'certificate_no' => $this->certificate_no,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'title' => $this->title,
            'student_id' => $this->student_id,
            'student' => $this->whenLoaded('student', fn () => new StudentSummaryResource($this->student)),
            'exam_id' => $this->exam_id,
            'lesson_id' => $this->lesson_id,
            'issued_on' => $this->issued_on?->toDateString(),
            'sent_at' => display_tz($this->sent_at)?->toIso8601String(),
            'pdf_url' => route('certificates.pdf', $this->resource),
        ];
    }
}
