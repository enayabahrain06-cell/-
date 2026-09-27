<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('source', 32)->default('manual');
            $table->date('booking_date')->index();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedBigInteger('exam_id')->nullable(); // FK added in create_exams_table
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['location_id', 'booking_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_bookings');
    }
};
