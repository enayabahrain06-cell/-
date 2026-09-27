<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->date('session_date')->index();
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('status', 32)->default('scheduled')->index();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->dateTime('attendance_taken_at')->nullable();
            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['lesson_id', 'session_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sessions');
    }
};
