<?php

namespace App\Exports;

use App\Services\Enrollment\EnrollmentImport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Bulk enrollment template: the input sheet plus a reference sheet of the circles this user may fill. */
class EnrollmentTemplateExport implements Export, WithMultipleSheets
{
    /** @param  list<array{id: int, name: string, package: string, teacher: ?string, free_seats: int}>  $circles */
    public function __construct(private array $circles) {}

    public function sheets(): array
    {
        return [
            new class implements FromCollection, WithHeadings, WithTitle
            {
                public function collection(): Collection
                {
                    return collect([['حسين علي المحروس', '2019-05-14', 'male', 'علي حسن المحروس', '36000005', '', 'juz_amma', '', '']]);
                }

                public function headings(): array
                {
                    return EnrollmentImport::COLUMNS;
                }

                public function title(): string
                {
                    return 'students';
                }
            },
            new class($this->circles) implements FromCollection, WithHeadings, WithTitle
            {
                public function __construct(private array $circles) {}

                public function collection(): Collection
                {
                    return collect($this->circles)->map(fn ($c) => [$c['id'], $c['name'], $c['package'], $c['teacher'], $c['free_seats']]);
                }

                public function headings(): array
                {
                    return ['circle_id', __('enrollment.col_circle'), __('enrollment.col_package'), __('enrollment.col_teacher'), __('enrollment.col_free_seats')];
                }

                public function title(): string
                {
                    return 'circles';
                }
            },
        ];
    }
}
