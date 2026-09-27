<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['variables' => 'array', 'is_active' => 'boolean'];
    }

    public function bodyFor(string $locale): string
    {
        return $locale === 'en' ? $this->body_en : $this->body_ar;
    }
}
