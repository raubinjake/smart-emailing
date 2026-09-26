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

    public const TOO_LONG_REMARK = 'Name or email exceeds 191 characters';

    /** Matches Schema::defaultStringLength(191) — the email_logs column width. */
    private const MAX_FIELD_LENGTH = 191;

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
                $remark  = $this->rejectionReason($row['name'], $row['email']);
                $isValid = $remark === null;
                $isValid ? $pending++ : $failed++;

                $records[] = [
                    'batch_id'   => $batch->id,
                    // Truncated to the column width: an overlong cell is
                    // already rejected above, and storing it untruncated would
                    // abort the insert and lose the whole file.
                    'name'       => mb_substr($row['name'], 0, self::MAX_FIELD_LENGTH),
                    'email'      => mb_substr($row['email'], 0, self::MAX_FIELD_LENGTH),
                    'status'     => $isValid ? EmailLog::STATUS_PENDING : EmailLog::STATUS_FAILED,
                    'attempts'   => 0,
                    'remarks'    => $remark,
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
     * Why this row cannot be sent, or null when it is valid.
     *
     * A row is valid when both columns are present, neither exceeds the
     * column width, and the email is RFC-parseable.
     *
     * @param  string  $name   the recipient's name
     * @param  string  $email  the recipient's address
     * @return string|null the remark to record, or null when the row is sendable
     */
    private function rejectionReason(string $name, string $email): ?string
    {
        if (mb_strlen($name) > self::MAX_FIELD_LENGTH || mb_strlen($email) > self::MAX_FIELD_LENGTH) {
            return self::TOO_LONG_REMARK;
        }

        if ($name === '' || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return self::INVALID_EMAIL_REMARK;
        }

        return null;
    }
}
