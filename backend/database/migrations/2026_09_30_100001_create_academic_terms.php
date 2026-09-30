<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Academic terms (الفصول الدراسية): the term selector at the top of the staff shell scopes packages, circles,
 * invoices and everything that follows them.
 *
 * Existing free-text packages.term / invoices.term values become terms and their rows are linked by
 * academic_term_id. The text columns are kept as they are, so rolling back loses nothing.
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

        $this->backfill();
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

    /** One term per distinct free-text label (trimmed); rows are linked, never changed otherwise. */
    private function backfill(): void
    {
        $raw = DB::table('packages')->whereNotNull('term')->distinct()->pluck('term')
            ->merge(DB::table('invoices')->whereNotNull('term')->distinct()->pluck('term'))
            ->filter(fn ($v) => trim((string) $v) !== '')
            ->unique()->values();

        $now = now();
        $ids = []; // trimmed label => term id
        foreach ($raw->groupBy(fn ($v) => trim((string) $v)) as $label => $variants) {
            $variants = $variants->all();
            $range = DB::table('packages')->whereIn('term', $variants)
                ->selectRaw('min(start_date) as s, max(end_date) as e, max(start_date) as ls')->first();
            $ids[$label] = DB::table('academic_terms')->insertGetId([
                'name_ar' => mb_substr($label, 0, 150),
                'name_en' => mb_substr($label, 0, 150),
                'start_date' => $range?->s,
                'end_date' => $range?->e,
                'legacy_label' => mb_substr($label, 0, 60),
                'is_current' => false,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('packages')->whereIn('term', $variants)->update(['academic_term_id' => $ids[$label]]);
            DB::table('invoices')->whereIn('term', $variants)->update(['academic_term_id' => $ids[$label]]);
        }

        // Invoices without their own label follow their package.
        DB::table('invoices')->whereNull('academic_term_id')->whereNotNull('package_id')
            ->whereIn('package_id', DB::table('packages')->whereNotNull('academic_term_id')->select('id'))
            ->orderBy('id')->each(function ($invoice) {
                DB::table('invoices')->where('id', $invoice->id)->update([
                    'academic_term_id' => DB::table('packages')->where('id', $invoice->package_id)->value('academic_term_id'),
                ]);
            });

        if ($ids === []) {
            return;
        }

        // Current term: the one running today, otherwise the one that started last.
        $today = $now->toDateString();
        $current = DB::table('academic_terms')->where('start_date', '<=', $today)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->orderByDesc('start_date')->value('id')
            // (NULL start dates sort last on every driver; PostgreSQL would put them first in a plain DESC.)
            ?? DB::table('academic_terms')->orderByRaw('case when start_date is null then 1 else 0 end')
                ->orderByDesc('start_date')->orderByDesc('id')->value('id');
        DB::table('academic_terms')->where('id', $current)->update(['is_current' => true]);
    }
};
