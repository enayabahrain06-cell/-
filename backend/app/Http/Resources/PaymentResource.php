<?php

namespace App\Http\Resources;

use App\Enums\MediaCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $image = $this->relationLoaded('media') ? $this->media->firstWhere('collection', MediaCollection::ReceiptImage) : null;

        return [
            'id' => $this->id,
            'receipt_no' => $this->receipt_no,
            'student' => $this->whenLoaded('student', fn () => ['id' => $this->student->id, 'full_name' => $this->student->full_name, 'student_no' => $this->student->student_no]),
            'amount_fils' => $this->amount_fils,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'reference' => $this->reference,
            'note' => $this->note,
            'received_by' => $this->whenLoaded('receiver', fn () => $this->receiver?->name),
            'paid_at' => display_tz($this->paid_at)?->toIso8601String(),
            'receipt_sent_at' => display_tz($this->receipt_sent_at)?->toIso8601String(),
            'receipt_pdf_url' => url("/api/payments/{$this->id}/receipt.pdf"),
            'receipt_image_media_id' => $image?->id,
            'receipt_image_url' => $image && Route::has('media.show') ? route('media.show', $image) : null,
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'invoice_id' => $a->invoice_id,
                'invoice_no' => $a->invoice?->invoice_no,
                'description' => $a->invoice?->description,
                'amount_fils' => $a->amount_fils,
            ])),
        ];
    }
}
