<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * البرامج and الرحلات: one engine for both (activities.type = program | trip). Fees are never stored here: the
 * registration keeps the ids of ordinary invoices (WalletService::createInvoice) for the fee and the book.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10); // program | trip
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->text('description')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete(); // a room (programs)
            $table->string('place', 300)->nullable(); // free text (trips)
            $table->unsignedInteger('seats')->nullable();
            $table->unsignedInteger('price_fils')->default(0);
            $table->string('gender', 10)->nullable(); // male | female | mixed; null = open to both
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->foreignId('level_id')->nullable()->constrained('levels')->nullOnDelete();
            $table->boolean('has_book')->default(false);
            $table->string('book_title', 200)->nullable();
            $table->unsignedInteger('book_price_fils')->default(0);
            $table->string('status', 10)->default('draft'); // draft | open | closed | done
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['academic_term_id', 'type']);
        });

        Schema::create('activity_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('status', 12)->default('registered'); // registered | waitlist | cancelled
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('registered_at')->nullable();
            $table->foreignId('fee_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('book_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();
            $table->unique(['activity_id', 'student_id']);
        });

        Schema::create('activity_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('status', 10); // present | absent | late | excused
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['activity_id', 'student_id', 'attendance_date']);
        });

        Schema::create('activity_book_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('delivered_at');
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['activity_id', 'student_id']);
        });

        Schema::create('activity_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->nullable(); // 0-100
            $table->string('grade', 50)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['activity_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_evaluations');
        Schema::dropIfExists('activity_book_deliveries');
        Schema::dropIfExists('activity_attendances');
        Schema::dropIfExists('activity_registrations');
        Schema::dropIfExists('activities');
    }
};
