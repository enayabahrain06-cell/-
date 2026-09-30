<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'student' => $this->whenLoaded('student', fn () => ['id' => $this->student->id, 'full_name' => $this->student->full_name, 'student_no' => $this->student->student_no, 'guardian_phone' => $this->student->guardian_phone]),
            'package' => $this->whenLoaded('package', fn () => $this->package ? ['id' => $this->package->id, 'name' => $this->package->localizedName(app()->getLocale())] : null),
            'description' => $this->description,
            'amount_fils' => $this->amount_fils,
            'paid_fils' => $this->paid_fils,
            'outstanding_fils' => $this->outstandingFils(),
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => in_array($this->status->value, ['open', 'partial'], true) && $this->due_date?->isPast(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'term' => \App\Models\AcademicTerm::nameFor($this->academic_term_id) ?? $this->term,
            'academic_term_id' => $this->academic_term_id,
            'created_at' => display_tz($this->created_at)?->toIso8601String(),
        ];
    }
}
