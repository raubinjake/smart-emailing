<?php

namespace App\Services;

use App\Jobs\SendBulkEmailJob;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use RuntimeException;

/**
 * Commits a draft batch: saves its message and queues one job per recipient.
 */
class BatchDispatchService
{
    private const SELECT_CHUNK = 500;

    /**
     * Save the message onto the batch and queue one job per pending recipient.
     *
     * The draft check and the flip to `processing` are a single conditional
     * UPDATE. Two concurrent requests (a double-submit, a refresh) both reach
     * the database, but InnoDB serialises them on the row: the first matches
     * `status = draft` and claims the batch, the second matches nothing and
     * throws. A read-then-write pair would let both pass the check and send
     * the whole list twice, which is not undoable.
     *
     * @param  EmailBatch  $batch    a batch in `draft` status
     * @param  string      $subject  the subject line, may contain `{{ name }}`
     * @param  string      $body     the HTML body, may contain `{{ name }}`
     * @return int the number of jobs queued
     * @throws RuntimeException when the batch is not a draft
     */
    public function dispatch(EmailBatch $batch, string $subject, string $body): int
    {
        $claimed = EmailBatch::whereKey($batch->id)
            ->where('status', EmailBatch::STATUS_DRAFT)
            ->update([
                'subject' => $subject,
                'body'    => $body,
                'status'  => EmailBatch::STATUS_PROCESSING,
            ]);

        if ($claimed === 0) {
            throw new RuntimeException('This batch has already been sent.');
        }

        // Keep the passed-in instance in step with the row we just claimed.
        $batch->refresh();

        $queued = 0;

        $batch->logs()
            ->where('status', EmailLog::STATUS_PENDING)
            ->chunkById(self::SELECT_CHUNK, function ($logs) use (&$queued) {
                foreach ($logs as $log) {
                    SendBulkEmailJob::dispatch($log->id);
                    $queued++;
                }
            });

        return $queued;
    }
}
