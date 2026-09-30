<?php

namespace App\Console\Commands;

use App\Services\Terms\TermConversion;
use App\Support\TermsReport;
use Illuminate\Console\Command;

/**
 * terms:check — run on production BEFORE migrating (it only reads, and works whether or not the academic-terms
 * migration has run). Lists the packages, invoices and exams that would end up without a term, so they can be
 * fixed first. Exit code 1 when anything is listed.
 */
class TermsCheckCommand extends Command
{
    protected $signature = 'terms:check';

    protected $description = 'Report packages, invoices and exams that would get no academic term';

    public function handle(TermConversion $conversion): int
    {
        $plan = $conversion->plan();
        TermsReport::terms($this, $plan);
        $problems = TermsReport::unmatched($this, $plan);

        if ($problems === 0) {
            $this->info('Every package, invoice and exam will belong to a term.');

            return self::SUCCESS;
        }
        $this->warn("{$problems} row(s) would have no term. Give them a term value (packages.term / invoices.term) or a package, then run this again.");

        return self::FAILURE;
    }
}
