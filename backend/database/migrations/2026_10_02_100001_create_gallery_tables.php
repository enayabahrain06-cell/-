<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 معرض الصور. An album belongs to a term and a gender track, and may link (never copy) to one class, level,
 * competition or activity by link_type + link_id. Photo files live in `media` (collections gallery_image,
 * gallery_thumb, gallery_video) and morph to album_photos. cover_photo_id has no foreign key because album_photos
 * references albums; the app clears it when that photo is deleted.
 *
 * students.photo_consent_withheld is عدم الموافقة على التصوير: uploads to an album linked to such a student warn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('album_date');
            $table->string('gender', 10); // male | female | mixed (track)
            $table->string('link_type', 20)->nullable(); // lesson | level | competition | activity
            $table->unsignedBigInteger('link_id')->nullable();
            $table->unsignedBigInteger('cover_photo_id')->nullable();
            $table->string('visibility', 20)->default('staff'); // staff | linked | all_guardians
            $table->boolean('allow_download')->default(false); // السماح بالتحميل (guardians)
            $table->timestamp('shared_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['academic_term_id', 'album_date']);
            $table->index(['link_type', 'link_id']);
        });

        Schema::create('album_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->constrained('albums')->cascadeOnDelete();
            $table->string('kind', 10)->default('photo'); // photo | video
            $table->string('caption', 500)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['album_id', 'position']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->boolean('photo_consent_withheld')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('photo_consent_withheld');
        });
        Schema::dropIfExists('album_photos');
        Schema::dropIfExists('albums');
    }
};
