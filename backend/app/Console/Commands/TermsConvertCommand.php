<?php

namespace App\Console\Commands;

use App\Services\Terms\TermConversion;
use App\Support\TermsReport;
use Illuminate\Console\Command;

/**
 * terms:convert — the same conversion the migration runs. --dry-run prints what it would create and link without
 * writing (also before the migration). Without --dry-run it links rows that still have no term (after the
 * migration), e.g. once terms:check problems were fixed.
 */
class TermsConvertCommand extends Command
{
    protected $signature = 'terms:convert {--dry-run : Print what would be created and linked; write nothing}';

    protected $description = 'Convert free-text term values into academic terms and link packages and invoices';

    public function handle(TermConversion $conversion): int
    {
        $plan = $conversion->plan();
        $dry = (bool) $this->option('dry-run');

        if (! $dry && ! $plan['migrated']) {
            $this->error('The academic terms migration has not run yet: run php artisan migrate (it converts automatically), or use --dry-run.');

            return self::FAILURE;
        }

        $this->line($dry ? '<comment>DRY RUN — nothing is written.</comment>' : 'Converting…');
        TermsReport::terms($this, $plan);
        $this->line('Invoices without their own label that follow their package: '.$plan['invoices_via_package']);
        if ($plan['current_label'] !== null) {
            $this->line("Current term would be: {$plan['current_label']}");
        }
        TermsReport::unmatched($this, $plan);

        if ($dry) {
            return self::SUCCESS;
        }
        $done = $conversion->apply($plan);
        $this->info("Terms created: {$done['terms_created']}; packages linked: {$done['packages_linked']}; invoices linked: {$done['invoices_linked']}.");

        return self::SUCCESS;
    }
}
