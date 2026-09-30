<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "اختبار" is now "امتحان" in the UI. The default exam WhatsApp templates were seeded into the database,
 * so seeding again does not change them. This updates a stored text only while it still equals the old default
 * exactly: a template an admin has edited is left alone. down() puts the old default back the same way.
 */
return new class extends Migration
{
    /** [table, key, column, old default, new default] */
    private const RENAMES = [
        ['message_templates', 'exam_reminder', 'name_ar', 'تذكير بالاختبار', 'تذكير بالامتحان'],
        ['message_templates', 'exam_reminder', 'body_ar', 'تذكير: اختبار {lesson} بتاريخ {date} الساعة {time}.', 'تذكير: امتحان {lesson} بتاريخ {date} الساعة {time}.'],
        ['message_templates', 'exam_result', 'name_ar', 'نتيجة الاختبار', 'نتيجة الامتحان'],
        ['message_templates', 'exam_result', 'body_ar', 'نتيجة {name} في اختبار {lesson}: {score}. الحالة: {status}', 'نتيجة {name} في امتحان {lesson}: {score}. الحالة: {status}'],
    ];

    public function up(): void
    {
        $this->swap(3, 4);
    }

    public function down(): void
    {
        $this->swap(4, 3);
    }

    private function swap(int $from, int $to): void
    {
        foreach (self::RENAMES as $r) {
            if (! Schema::hasTable($r[0]) || ! Schema::hasColumn($r[0], $r[2])) {
                continue;
            }
            DB::table($r[0])->where('key', $r[1])->where($r[2], $r[$from])->update([$r[2] => $r[$to], 'updated_at' => now()]);
        }
    }
};
