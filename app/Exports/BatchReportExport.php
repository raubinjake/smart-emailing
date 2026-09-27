<?php

namespace App\Exports;

use App\Models\EmailBatch;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Every recipient row of one batch, including rows rejected at import.
 */
class BatchReportExport implements FromCollection, WithHeadings
{
    /**
     * @param  EmailBatch   $batch   the batch to export
     * @param  string|null  $status  optional `pending`/`sent`/`failed` filter
     */
    public function __construct(
        private EmailBatch $batch,
        private ?string $status = null,
    ) {
    }

    /**
     * Every log row belonging to the batch (optionally filtered by status),
     * shaped into the flat arrays the spreadsheet writer expects.
     *
     * @return Collection<int, array<int, mixed>>
     */
    public function collection(): Collection
    {
        return $this->batch->logs()
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->orderBy('id')
            ->get()
            ->map(fn ($log) => [
                $this->defuse($log->displayName()),
                $this->defuse($log->email),
                ucfirst($log->status),
                ($log->sent_at ?? $log->created_at)->format('Y-m-d'),
                ($log->sent_at ?? $log->created_at)->format('H:i:s'),
                $log->attempts,
                $this->defuse($log->remarks ?? ''),
            ]);
    }

    /**
     * Neutralise a spreadsheet formula in a cell value.
     *
     * Recipient names come from a file the admin did not write, and remarks
     * carry text from a remote SMTP server. A value starting with =, +, -, @
     * or a control character is executed as a formula on open, so prefix it
     * with an apostrophe to force Excel to treat it as text.
     *
     * @param  string  $value  the raw cell value
     * @return string the value, safe to write to a cell
     */
    private function defuse(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Name', 'Email', 'Status', 'Date', 'Time', 'Attempts', 'Remarks'];
    }
}
