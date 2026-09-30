<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * غرف المستويات: rooms assigned to a level for a term. The screen also shows the rooms a level already uses
 * through its circles' halls and its timetable periods; this table holds the explicit assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('level_id')->constrained('levels')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['academic_term_id', 'level_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_rooms');
    }
};
