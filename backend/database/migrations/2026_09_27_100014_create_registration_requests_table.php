<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 20)->unique();
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('full_name', 150);
            $table->date('birth_date');
            $table->string('gender', 10);
            $table->string('student_phone', 20)->nullable();
            $table->string('guardian_name', 150);
            $table->string('guardian_phone', 20)->index();
            $table->string('memorization_level', 40);
            $table->string('locale', 5)->default('ar');
            $table->unsignedTinyInteger('age_at_start');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('waitlist_position')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['package_id', 'status', 'waitlist_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_requests');
    }
};
