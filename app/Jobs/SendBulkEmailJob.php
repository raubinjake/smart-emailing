<?php

namespace App\Jobs;

use App\Mail\BulkEmailMessage;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Services\DynamicMailerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Sends one recipient's message and records the outcome on its email_logs row.
 */
class SendBulkEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> seconds to wait before each retry */
    public array $backoff = [10, 30, 60];

    /**
     * @param  int  $emailLogId  the recipient row to send
     */
    public function __construct(public int $emailLogId)
    {
    }

    /**
     * @return array<int, string> Horizon tags
     */
    public function tags(): array
    {
        return ['bulk-email', 'log:' . $this->emailLogId];
    }

    /**
     * Render and send the message, then advance the batch counters.
     *
     * @param  DynamicMailerService  $mailer  builds the transport from the active SMTP profile
     * @throws Throwable rethrown so Laravel applies the backoff and retries
     */
    public function handle(DynamicMailerService $mailer): void
    {
        $log = EmailLog::with('batch')->find($this->emailLogId);

        if ($log === null || $log->status !== EmailLog::STATUS_PENDING) {
            return;
        }

        try {
            $mailerName = $mailer->configureActive();
            $batch      = $log->batch;

            Mail::mailer($mailerName)
                ->to($log->email, $log->name)
                ->send(new BulkEmailMessage(
                    $this->substitute($batch->subject, $log->name),
                    $this->substitute($batch->body, $log->name),
                ));

            $log->update([
                'status'  => EmailLog::STATUS_SENT,
                'sent_at' => now(),
                'remarks' => null,
            ]);

            $this->settle($batch, 'sent_count');
        } catch (Throwable $e) {
            $log->increment('attempts');
            $log->update(['remarks' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Permanent failure after the final attempt.
     */
    public function failed(Throwable $exception): void
    {
        $log = EmailLog::with('batch')->find($this->emailLogId);

        if ($log === null || $log->status !== EmailLog::STATUS_PENDING) {
            return;
        }

        $log->update([
            'status'  => EmailLog::STATUS_FAILED,
            'remarks' => $exception->getMessage(),
        ]);

        $this->settle($log->batch, 'failed_count');
    }

    /**
     * Atomically move one recipient out of pending, and close the batch at zero.
     *
     * Uses SQL increments rather than read-modify-write because parallel
     * workers would otherwise race and lose updates.
     *
     * @param  EmailBatch  $batch   the batch to advance
     * @param  string      $column  `sent_count` or `failed_count`
     */
    private function settle(EmailBatch $batch, string $column): void
    {
        // Whitelisted because the column name is interpolated into raw SQL.
        if (! in_array($column, ['sent_count', 'failed_count'], true)) {
            throw new InvalidArgumentException("Cannot settle on column [{$column}].");
        }

        EmailBatch::whereKey($batch->id)->update([
            $column         => DB::raw($column . ' + 1'),
            'pending_count' => DB::raw('GREATEST(pending_count - 1, 0)'),
        ]);

        $fresh = $batch->fresh();

        if ($fresh !== null && $fresh->pending_count === 0) {
            $fresh->update(['status' => EmailBatch::STATUS_COMPLETED]);
        }
    }

    /**
     * Replace the `{{ name }}` placeholder with the recipient's name.
     */
    private function substitute(?string $template, string $name): string
    {
        return str_replace(
            ['{{ name }}', '{{name}}'],
            $name,
            (string) $template,
        );
    }
}
