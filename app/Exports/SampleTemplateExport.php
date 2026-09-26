<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The blank recipient template offered on the upload screen.
 */
class SampleTemplateExport implements FromCollection, WithHeadings
{
    /**
     * Two dummy rows showing the expected `name`/`email` shape.
     *
     * @return Collection<int, array<int, string>>
     */
    public function collection(): Collection
    {
        return collect([
            ['Ada Lovelace', 'ada@example.com'],
            ['Alan Turing', 'alan@example.com'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['name', 'email'];
    }
}
