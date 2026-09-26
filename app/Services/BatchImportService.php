<?php

namespace App\Services;

use App\Imports\BulkEmailImport;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Parses an uploaded recipient file into a draft batch.
 *
 * Every row of the file becomes an email_logs row — valid rows as `pending`,
 * invalid rows as `failed` — so the batch report accounts for the whole file.
 */
class BatchImportService
{
    public const INVALID_EMAIL_REMARK = 'Invalid email syntax';

    private const INSERT_CHUNK = 500;

    /**
     * @param  UploadedFile  $file  an .xlsx or .csv with `name` and `email` headings
     * @return EmailBatch the created draft batch, with logs loaded
     */
    public function import(UploadedFile $file): EmailBatch
    {
        $originalName = $file->getClientOriginalName();

        $reader = new BulkEmailImport();
        Excel::import($reader, $file);

        $rows = $this->normalise($reader->rows);

        $storedPath = $file->store('imports');

        return DB::transaction(function () use ($rows, $originalName, $storedPath) {
            $batch = EmailBatch::create([
                'batch_uuid'  => (string) Str::uuid(),
                'file_name'   => $originalName,
                'stored_path' => $storedPath,
                'status'      => EmailBatch::STATUS_DRAFT,
            ]);

            $pending = 0;
            $failed  = 0;
            $now     = now();
            $records = [];

            foreach ($rows as $row) {
                $isValid = $this->isValid($row['name'], $row['email']);
                $isValid ? $pending++ : $failed++;

                $records[] = [
                    'batch_id'   => $batch->id,
                    'name'       => $row['name'],
                    'email'      => $row['email'],
                    'status'     => $isValid ? EmailLog::STATUS_PENDING : EmailLog::STATUS_FAILED,
                    'attempts'   => 0,
                    'remarks'    => $isValid ? null : self::INVALID_EMAIL_REMARK,
                    'sent_at'    => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($records, self::INSERT_CHUNK) as $chunk) {
                EmailLog::insert($chunk);
            }

            $batch->update([
                'total_emails'  => count($records),
                'pending_count' => $pending,
                'failed_count'  => $failed,
            ]);

            return $batch->fresh('logs');
        });
    }

    /**
     * Drop fully-blank rows and trim the two columns we care about.
     *
     * @param  array  $rows  raw heading-keyed rows from the reader
     * @return array<int, array{name: string, email: string}>
     */
    private function normalise(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            $name  = trim((string) ($row['name'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));

            if ($name === '' && $email === '') {
                continue;
            }

            $clean[] = ['name' => $name, 'email' => $email];
        }

        return $clean;
    }

    /**
     * A row is valid when both columns are present and the email is RFC-parseable.
     */
    private function isValid(string $name, string $email): bool
    {
        return $name !== ''
            && $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
