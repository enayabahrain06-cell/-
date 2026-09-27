<?php

namespace App\Models;

use App\Enums\CertificateType;
use App\Enums\MediaCollection;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Model;

/**
 * One editable bilingual template per certificate type. Signature images are media in the
 * "signature" collection, told apart by original_name signature_1 / signature_2.
 */
class CertificateTemplate extends Model
{
    use HasMedia;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => CertificateType::class, 'show_photo' => 'boolean'];
    }

    /** The template for a type, created from the default texts on first use. */
    public static function forType(CertificateType $type): self
    {
        return static::firstOrCreate(['type' => $type->value], [
            'title_ar' => __("certificates.defaults.{$type->value}.title", [], 'ar'),
            'title_en' => __("certificates.defaults.{$type->value}.title", [], 'en'),
            'body_ar' => __("certificates.defaults.{$type->value}.body", [], 'ar'),
            'body_en' => __("certificates.defaults.{$type->value}.body", [], 'en'),
            'ornament_level' => 'full',
            'show_photo' => false,
        ]);
    }

    public function title(string $locale): string
    {
        return $locale === 'en' ? $this->title_en : $this->title_ar;
    }

    public function body(string $locale): string
    {
        return $locale === 'en' ? $this->body_en : $this->body_ar;
    }

    public function signature(int $slot): ?Media
    {
        return $this->media()->where('collection', MediaCollection::Signature->value)
            ->where('original_name', "signature_{$slot}")->latest('id')->first();
    }
}
