<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'name' => app()->getLocale() === 'en' ? $this->name_en : $this->name_ar,
            'body_ar' => $this->body_ar,
            'body_en' => $this->body_en,
            'variables' => $this->variables ?? [],
            'is_active' => $this->is_active,
            'updated_at' => display_tz($this->updated_at)?->toIso8601String(),
        ];
    }
}
