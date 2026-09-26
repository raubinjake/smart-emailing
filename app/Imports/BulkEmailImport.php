<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads a recipient file into raw rows keyed by heading.
 * Validation happens in BatchImportService, not here.
 */
class BulkEmailImport implements ToArray, WithHeadingRow
{
    public array $rows = [];

    /**
     * @param  array  $rows  rows keyed by heading name
     */
    public function array(array $rows): void
    {
        $this->rows = $rows;
    }
}
