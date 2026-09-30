<?php

use App\Services\Terms\TermConversion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic terms (الفصول الدراسية): the term selector at the top of the staff shell scopes packages, circles,
 * invoices and everything that follows them.
 *
 * Existing free-text packages.term / invoices.term values become terms and their rows are linked by
 * academic_term_id (TermConversion; preview it first with `php artisan terms:check` or
 * `php artisan terms:convert --dry-run`). The text columns are kept as they are, so rolling back loses nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 150);
            $table->string('name_en', 150);
            $table->string('academic_year', 20)->nullable(); // e.g. 2026/2027
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false)->index();
            $table->string('legacy_label', 60)->nullable()->index(); // the free-text term it was created from
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
        });

        (new TermConversion)->apply();
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_term_id');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_term_id');
        });

        Schema::dropIfExists('academic_terms');
    }
};
