<?php

namespace App\Support;

use Illuminate\Console\Command;

/** Console output shared by terms:check and terms:convert (plan from App\Services\Terms\TermConversion). */
final class TermsReport
{
    public static function terms(Command $c, array $plan): void
    {
        $c->line($plan['migrated'] ? 'Migration state: migrated (only rows without a term are considered).' : 'Migration state: not migrated yet.');
        if ($plan['terms'] === []) {
            $c->line('No free-text term values to convert.');

            return;
        }
        $c->table(['Term label', 'Action', 'Dates', 'Packages to link', 'Invoices to link'], array_map(fn ($t) => [
            $t['label'].(count($t['variants']) > 1 ? ' ('.count($t['variants']).' spellings)' : ''),
            $t['existing_id'] ? "reuse term #{$t['existing_id']}" : 'create',
            ($t['start_date'] ?? '—').' → '.($t['end_date'] ?? '—'),
            $t['packages'],
            $t['invoices'],
        ], $plan['terms']));
    }

    /** @return int number of rows listed */
    public static function unmatched(Command $c, array $plan): int
    {
        $u = $plan['unmatched'];
        if ($u['packages']) {
            $c->warn('Packages with an empty term value:');
            $c->table(['id', 'name', 'term', 'start_date', 'status'], array_map(fn ($p) => [$p->id, $p->name, var_export($p->term, true), $p->start_date, $p->status], $u['packages']));
        }
        if ($u['invoices']) {
            $c->warn('Invoices with an empty term and no package that has one:');
            $c->table(['id', 'invoice_no', 'student_id', 'package_id', 'due_date'], array_map(fn ($i) => [$i->id, $i->invoice_no, $i->student_id, $i->package_id ?? '—', $i->due_date], $u['invoices']));
        }
        if ($u['exams']) {
            $c->warn('Exams that would fall under no term:');
            $c->table(['id', 'name', 'exam_date', 'package_id', 'reason'], array_map(fn ($e) => [
                $e->id, $e->name, $e->exam_date, $e->package_id ?? $e->lesson_package_id ?? '—',
                $e->reason === 'package_without_term' ? 'its package has no term' : 'no package or class, and its date is outside every term',
            ], $u['exams']));
        }

        return count($u['packages']) + count($u['invoices']) + count($u['exams']);
    }
}
