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
                $log->name,
                $log->email,
                ucfirst($log->status),
                $log->sent_at?->format('Y-m-d') ?? $log->created_at->format('Y-m-d'),
                $log->sent_at?->format('H:i:s') ?? $log->created_at->format('H:i:s'),
                $log->attempts,
                $log->remarks ?? '',
            ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Name', 'Email', 'Status', 'Date', 'Time', 'Attempts', 'Remarks'];
    }
}
