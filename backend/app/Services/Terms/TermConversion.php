<?php

namespace App\Services\Terms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts the free-text packages.term / invoices.term values into academic_terms and links the rows.
 *
 * One rule set for three callers: the 2026_09_30_100001 migration, `terms:convert` (with --dry-run) and
 * `terms:check`. plan() only reads, and works before the migration (the new table and columns may not exist yet).
 * apply() is idempotent: it reuses a term with the same legacy label and only links rows that have no term.
 * Only the query builder is used, so it behaves the same on SQLite, MySQL and PostgreSQL.
 */
final class TermConversion
{
    /**
     * @return array{
     *   migrated: bool,
     *   terms: list<array{label:string, variants:list<string>, existing_id:?int, start_date:?string, end_date:?string, packages:int, invoices:int}>,
     *   invoices_via_package: int,
     *   current_label: ?string,
     *   unmatched: array{packages: list<object>, invoices: list<object>, exams: list<object>}
     * }
     */
    public function plan(): array
    {
        $migrated = Schema::hasTable('academic_terms') && Schema::hasColumn('packages', 'academic_term_id') && Schema::hasColumn('invoices', 'academic_term_id');
        $packages = DB::table('packages')->when($migrated, fn ($q) => $q->whereNull('academic_term_id'));
        $invoices = DB::table('invoices')->when($migrated, fn ($q) => $q->whereNull('academic_term_id'));

        $raw = (clone $packages)->whereNotNull('term')->distinct()->pluck('term')
            ->merge((clone $invoices)->whereNotNull('term')->distinct()->pluck('term'))
            ->filter(fn ($v) => trim((string) $v) !== '')->unique()->values();

        $existing = $migrated ? DB::table('academic_terms')->whereNotNull('legacy_label')->pluck('id', 'legacy_label') : collect();

        $terms = [];
        foreach ($raw->groupBy(fn ($v) => trim((string) $v)) as $label => $variants) {
            $variants = $variants->values()->all();
            $range = DB::table('packages')->whereIn('term', $variants)
                ->selectRaw('min(start_date) as s, max(end_date) as e')->first();
            $label = mb_substr((string) $label, 0, 60);
            $terms[] = [
                'label' => $label,
                'variants' => $variants,
                'existing_id' => $existing[$label] ?? null,
                'start_date' => $range?->s ? substr((string) $range->s, 0, 10) : null,
                'end_date' => $range?->e ? substr((string) $range->e, 0, 10) : null,
                'packages' => (clone $packages)->whereIn('term', $variants)->count(),
                'invoices' => (clone $invoices)->whereIn('term', $variants)->count(),
            ];
        }

        $labelled = collect($terms)->pluck('variants')->flatten()->all();
        $emptyTerm = fn ($q) => $q->where(fn ($w) => $w->whereNull('term')->orWhereRaw("trim(term) = ''"));

        // Packages without a usable label.
        $unmatchedPackages = (clone $packages)->where($emptyTerm)->orderBy('id')->get(['id', 'name', 'term', 'start_date', 'status']);
        $unmatchedIds = $unmatchedPackages->pluck('id')->all();

        // Invoices without a label follow their package, when that package gets (or has) a term.
        $invoicesNoLabel = (clone $invoices)->where($emptyTerm);
        $viaPackage = (clone $invoicesNoLabel)->whereNotNull('package_id')->whereNotIn('package_id', $unmatchedIds ?: [0])->count();
        $unmatchedInvoices = (clone $invoicesNoLabel)
            ->where(fn ($w) => $w->whereNull('package_id')->orWhereIn('package_id', $unmatchedIds ?: [0]))
            ->orderBy('id')->get(['id', 'invoice_no', 'student_id', 'package_id', 'term', 'due_date']);

        // Exams follow their package or their circle's package; unlinked exams are placed by date.
        $ranges = collect($terms)->map(fn ($t) => [$t['start_date'], $t['end_date']])
            ->merge($migrated ? DB::table('academic_terms')->get(['start_date', 'end_date'])->map(fn ($t) => [$t->start_date, $t->end_date]) : [])
            ->values();
        $unmatchedExams = DB::table('exams')->leftJoin('lessons', 'lessons.id', '=', 'exams.lesson_id')
            ->orderBy('exams.id')
            ->get(['exams.id', 'exams.name', 'exams.exam_date', 'exams.package_id', 'lessons.package_id as lesson_package_id'])
            ->map(function ($e) use ($unmatchedIds, $ranges) {
                $pkg = $e->package_id ?? $e->lesson_package_id;
                if ($pkg !== null) {
                    return in_array($pkg, $unmatchedIds) ? (object) ((array) $e + ['reason' => 'package_without_term']) : null;
                }
                $date = substr((string) $e->exam_date, 0, 10);
                $inside = $ranges->contains(fn ($r) => ($r[0] === null || substr((string) $r[0], 0, 10) <= $date) && ($r[1] === null || substr((string) $r[1], 0, 10) >= $date));

                return $inside ? null : (object) ((array) $e + ['reason' => 'date_outside_terms']);
            })->filter()->values();

        return [
            'migrated' => $migrated,
            'terms' => $terms,
            'invoices_via_package' => $viaPackage,
            'current_label' => $this->currentLabel($terms, $migrated),
            'unmatched' => ['packages' => $unmatchedPackages->all(), 'invoices' => $unmatchedInvoices->all(), 'exams' => $unmatchedExams->all()],
        ];
    }

    /** Write the plan: create (or reuse) terms, link rows that have no term yet, set a current term if none. */
    public function apply(?array $plan = null): array
    {
        $plan ??= $this->plan();
        $now = now();
        $created = 0;
        $linkedPackages = 0;
        $linkedInvoices = 0;

        foreach ($plan['terms'] as $t) {
            $id = $t['existing_id'] ?? DB::table('academic_terms')->where('legacy_label', $t['label'])->value('id');
            if (! $id) {
                $id = DB::table('academic_terms')->insertGetId([
                    'name_ar' => $t['label'], 'name_en' => $t['label'], 'legacy_label' => $t['label'],
                    'start_date' => $t['start_date'], 'end_date' => $t['end_date'],
                    'is_current' => false, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $created++;
            }
            $linkedPackages += DB::table('packages')->whereNull('academic_term_id')->whereIn('term', $t['variants'])->update(['academic_term_id' => $id]);
            $linkedInvoices += DB::table('invoices')->whereNull('academic_term_id')->whereIn('term', $t['variants'])->update(['academic_term_id' => $id]);
        }

        // Invoices without their own label follow their package.
        DB::table('invoices')->whereNull('academic_term_id')->whereNotNull('package_id')
            ->whereIn('package_id', DB::table('packages')->whereNotNull('academic_term_id')->select('id'))
            ->orderBy('id')->each(function ($invoice) use (&$linkedInvoices) {
                $linkedInvoices += DB::table('invoices')->where('id', $invoice->id)->update([
                    'academic_term_id' => DB::table('packages')->where('id', $invoice->package_id)->value('academic_term_id'),
                ]);
            });

        if (! DB::table('academic_terms')->where('is_current', true)->exists()) {
            $today = $now->toDateString();
            $current = DB::table('academic_terms')->where('start_date', '<=', $today)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
                ->orderByDesc('start_date')->value('id')
                // NULL start dates sort last on every driver (PostgreSQL would put them first in a plain DESC).
                ?? DB::table('academic_terms')->orderByRaw('case when start_date is null then 1 else 0 end')
                    ->orderByDesc('start_date')->orderByDesc('id')->value('id');
            if ($current) {
                DB::table('academic_terms')->where('id', $current)->update(['is_current' => true]);
            }
        }

        return ['terms_created' => $created, 'packages_linked' => $linkedPackages, 'invoices_linked' => $linkedInvoices];
    }

    /** Label of the term that apply() would make current (when no term is current yet). */
    private function currentLabel(array $terms, bool $migrated): ?string
    {
        if ($migrated && DB::table('academic_terms')->where('is_current', true)->exists()) {
            return null;
        }
        $today = now()->toDateString();
        $all = collect($terms)->sortByDesc(fn ($t) => $t['start_date'] ?? '');
        $running = $all->first(fn ($t) => $t['start_date'] !== null && $t['start_date'] <= $today && ($t['end_date'] === null || $t['end_date'] >= $today));

        return ($running ?? $all->first(fn ($t) => $t['start_date'] !== null) ?? $all->first())['label'] ?? null;
    }
}
