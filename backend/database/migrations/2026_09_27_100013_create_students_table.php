<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_no', 20)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('guardian_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('full_name', 150);
            $table->date('birth_date')->index();
            $table->string('gender', 10)->index();
            $table->string('student_phone', 20)->nullable()->index();
            $table->string('guardian_name', 150);
            $table->string('guardian_phone', 20)->index(); // kept in sync from guardian user's phone
            $table->string('memorization_level', 40)->index();
            $table->string('locale', 5)->default('ar');
            $table->string('photo_path', 500)->nullable();       // denormalised cache of media(collection=photo)
            $table->string('photo_thumb_path', 500)->nullable(); // denormalised cache of media(collection=photo_thumb)
            $table->unsignedInteger('yearly_target_ayahs')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
