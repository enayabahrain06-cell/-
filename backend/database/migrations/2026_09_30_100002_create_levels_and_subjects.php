<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Levels (المستويات) and subjects (المواد) master data.
 *
 * - A level is a layer above circles: one level holds several circles (lessons.level_id, optional).
 *   Circles keep working exactly as before when no level is set.
 * - Subjects are editable. Quran is seeded as a system subject (code "quran") that cannot be deleted;
 *   the memorization ledger (student_progress) is unchanged and does not reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('code', 30)->nullable()->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('level_id')->nullable()->constrained('levels')->nullOnDelete();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('code', 30)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false); // system subjects (Quran) cannot be deleted or re-coded
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        DB::table('subjects')->insert([
            'name_ar' => 'القرآن الكريم',
            'name_en' => 'Holy Quran',
            'code' => 'quran',
            'is_system' => true,
            'sort' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('level_id');
        });

        Schema::dropIfExists('levels');
    }
};
