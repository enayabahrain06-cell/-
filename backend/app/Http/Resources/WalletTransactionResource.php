<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'amount_fils' => $this->amount_fils,
            'balance_after_fils' => $this->balance_after_fils,
            'reference' => $this->reference,
            'invoice_no' => $this->whenLoaded('invoice', fn () => $this->invoice?->invoice_no),
            'payment_method' => $this->whenLoaded('payment', fn () => $this->payment?->method?->value),
            'note' => $this->note,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => display_tz($this->created_at)?->toIso8601String(),
        ];
    }
}
