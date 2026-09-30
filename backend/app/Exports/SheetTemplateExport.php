<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * An import template: the input sheet (headings + sample rows) and optional reference sheets
 * (e.g. the subjects and levels a sheet may name). Used by the archive and subject lessons imports.
 */
class SheetTemplateExport implements Export, WithMultipleSheets
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $sample
     * @param  array<string, array{headings: list<string>, rows: list<list<mixed>>}>  $references  title => sheet
     */
    public function __construct(private string $title, private array $headings, private array $sample = [], private array $references = []) {}

    public function sheets(): array
    {
        $sheets = [self::sheet($this->title, $this->headings, $this->sample)];
        foreach ($this->references as $title => $ref) {
            $sheets[] = self::sheet($title, $ref['headings'], $ref['rows']);
        }

        return $sheets;
    }

    private static function sheet(string $title, array $headings, array $rows): object
    {
        return new class($title, $headings, $rows) implements FromCollection, WithHeadings, WithTitle
        {
            public function __construct(private string $title, private array $headings, private array $rows) {}

            public function collection(): Collection
            {
                return collect($this->rows);
            }

            public function headings(): array
            {
                return $this->headings;
            }

            public function title(): string
            {
                return $this->title;
            }
        };
    }
}
