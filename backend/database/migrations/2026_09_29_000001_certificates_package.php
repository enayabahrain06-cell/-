<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves certificates to the reusable package (packages/certificates) schema:
 *  - certificates.student_id → recipient (polymorphic), lesson_id → context (polymorphic);
 *    exam_id / honor_period_id / competition_id are already covered by source + source_id.
 *  - certificate_templates.title_ar/_en, body_ar/_en → title / body JSON maps keyed by locale.
 * Existing rows are copied; files stay in media.
 */
return new class extends Migration
{
    private const STUDENT = 'App\\Models\\Student';

    private const LESSON = 'App\\Models\\Lesson';

    public function up(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->json('title')->nullable();
            $table->json('body')->nullable();
        });
        foreach (DB::table('certificate_templates')->get() as $t) {
            DB::table('certificate_templates')->where('id', $t->id)->update([
                'title' => json_encode(['ar' => $t->title_ar, 'en' => $t->title_en], JSON_UNESCAPED_UNICODE),
                'body' => json_encode(['ar' => $t->body_ar, 'en' => $t->body_en], JSON_UNESCAPED_UNICODE),
            ]);
        }
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropColumn(['title_ar', 'title_en', 'body_ar', 'body_en']);
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->string('recipient_type', 120)->nullable();
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('context_type', 120)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->index(['recipient_type', 'recipient_id']);
            $table->index(['context_type', 'context_id']);
        });

        DB::table('certificates')->update(['recipient_type' => self::STUDENT, 'recipient_id' => DB::raw('student_id')]);
        DB::table('certificates')->whereNotNull('lesson_id')->update(['context_type' => self::LESSON, 'context_id' => DB::raw('lesson_id')]);
        // Early exam certificates were backfilled as "legacy": keep their exam link as the source.
        DB::table('certificates')->whereNotNull('exam_id')->where(fn ($q) => $q->where('source', 'legacy')->orWhereNull('source_id'))
            ->update(['source' => 'exam', 'source_id' => DB::raw('exam_id')]);

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('competition_id');
            $table->dropConstrainedForeignId('honor_period_id');
            $table->dropConstrainedForeignId('lesson_id');
            $table->dropConstrainedForeignId('exam_id');
            $table->dropConstrainedForeignId('student_id');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->constrained('students')->cascadeOnDelete();
            $table->foreignId('exam_id')->nullable()->constrained('exams')->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('honor_period_id')->nullable()->constrained('honor_periods')->nullOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained('competitions')->nullOnDelete();
        });
        DB::table('certificates')->where('recipient_type', self::STUDENT)->update(['student_id' => DB::raw('recipient_id')]);
        DB::table('certificates')->where('context_type', self::LESSON)->update(['lesson_id' => DB::raw('context_id')]);
        DB::table('certificates')->where('source', 'exam')->update(['exam_id' => DB::raw('source_id')]);
        DB::table('certificates')->where('source', 'honor_period')->update(['honor_period_id' => DB::raw('source_id')]);
        DB::table('certificates')->where('source', 'competition')->update(['competition_id' => DB::raw('source_id')]);
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndex(['recipient_type', 'recipient_id']);
            $table->dropIndex(['context_type', 'context_id']);
            $table->dropColumn(['recipient_type', 'recipient_id', 'context_type', 'context_id']);
        });

        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->string('title_ar', 150)->default('');
            $table->string('title_en', 150)->default('');
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
        });
        foreach (DB::table('certificate_templates')->get() as $t) {
            $title = json_decode((string) $t->title, true) ?: [];
            $body = json_decode((string) $t->body, true) ?: [];
            DB::table('certificate_templates')->where('id', $t->id)->update([
                'title_ar' => $title['ar'] ?? '', 'title_en' => $title['en'] ?? '',
                'body_ar' => $body['ar'] ?? '', 'body_en' => $body['en'] ?? '',
            ]);
        }
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropColumn(['title', 'body']);
        });
    }
};
