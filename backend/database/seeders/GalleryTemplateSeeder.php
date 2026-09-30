<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Reference data: the WhatsApp template sent when an album of معرض الصور is shared with guardians (AR/EN).
 * Idempotent; admin edits survive re-seeding. Variables: {name} {album} {date} {link}
 */
class GalleryTemplateSeeder extends Seeder
{
    public function run(): void
    {
        MessageTemplate::firstOrCreate(['key' => 'album_shared'], [
            'name_ar' => 'مشاركة ألبوم صور', 'name_en' => 'Photo album shared',
            'body_ar' => "أُضيف ألبوم صور جديد «{album}» ({date}) يخص {name}. يمكنكم مشاهدته في بوابة الأسرة: {link}",
            'body_en' => "A new photo album \"{album}\" ({date}) for {name} is available in the family portal: {link}",
            'variables' => ['name', 'album', 'date', 'link'], 'is_active' => true,
        ]);
    }
}
