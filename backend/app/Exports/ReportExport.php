<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** One sheet per report section (plus a summary sheet); right-to-left sheets when the locale is Arabic. */
class ReportExport implements Export, WithMultipleSheets
{
    public function __construct(private array $report) {}

    public function sheets(): array
    {
        $rtl = app()->getLocale() === 'ar';
        $sheets = [];
        if (! empty($this->report['summary'])) {
            $sheets[] = new ReportSheet(__('reports.summary'), [__('reports.item'), __('reports.value')], array_map(fn ($r) => [$r[0], $r[1]], $this->report['summary']), $rtl);
        }
        foreach ($this->report['sections'] as $s) {
            $sheets[] = new ReportSheet($s['title'], $s['headings'], $s['rows'], $rtl);
        }

        return $sheets;
    }
}
