<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One editable template per certificate type, and certificates issued to any model (the recipient),
 * optionally inside a context (a course, class …). Files (PDF, signatures) live in the configured FileStore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32)->unique();
            $table->json('title');
            $table->json('body');
            $table->string('signature1_name', 120)->nullable();
            $table->string('signature1_title', 120)->nullable();
            $table->string('signature2_name', 120)->nullable();
            $table->string('signature2_title', 120)->nullable();
            $table->string('ornament_level', 10)->default('full');
            $table->boolean('show_photo')->default(false);
            $table->timestamps();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('certificate_no', 30)->unique();
            $table->string('recipient_type', 120);
            $table->unsignedBigInteger('recipient_id');
            $table->string('context_type', 120)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('type', 32)->index();
            $table->foreignId('template_id')->nullable()->constrained('certificate_templates')->nullOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->string('title', 200);
            $table->string('achievement', 255)->nullable();
            $table->string('grade', 16)->nullable();
            $table->json('details')->nullable();
            $table->string('source', 20)->default('manual')->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('verify_token', 40)->nullable()->unique();
            $table->date('issued_on')->index();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamps();
            $table->index(['recipient_type', 'recipient_id']);
            $table->index(['context_type', 'context_id']);
            $table->index(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_templates');
    }
};
