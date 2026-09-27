<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name_ar', 150);
            $table->string('name_en', 150);
            $table->text('body_ar');
            $table->text('body_en');
            $table->text('variables')->nullable(); // JSON list of allowed placeholders
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->string('recipient_phone', 20)->index();
            $table->string('recipient_type', 20)->default('guardian');
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40)->index();
            $table->string('template_key', 60)->nullable();
            $table->string('locale', 5)->default('ar');
            $table->text('body');
            $table->string('status', 32)->default('queued')->index();
            $table->string('provider', 20)->nullable();
            $table->string('provider_message_id', 120)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('sent_at')->nullable()->index();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('severity', 16)->default('warning');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('subject_type', 150)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 32)->default('open')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('message_logs');
        Schema::dropIfExists('message_templates');
    }
};
