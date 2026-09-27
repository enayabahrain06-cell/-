<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/** One report section as a sheet (bold header row, RTL for Arabic). */
class ReportSheet implements FromArray, WithHeadings, WithTitle, WithEvents
{
    public function __construct(private string $title, private array $headings, private array $rows, private bool $rtl) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        // Excel sheet names: max 31 chars, no []:*?/\
        return mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $this->title), 0, 31);
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $e) {
            $e->sheet->getDelegate()->setRightToLeft($this->rtl);
            $e->sheet->getDelegate()->getStyle('A1:'.$e->sheet->getDelegate()->getHighestColumn().'1')->getFont()->setBold(true);
        }];
    }
}
