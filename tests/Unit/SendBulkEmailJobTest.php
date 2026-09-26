<?php

namespace Tests\Unit;

use App\Jobs\SendBulkEmailJob;
use App\Mail\BulkEmailMessage;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Models\SmtpSetting;
use App\Services\DynamicMailerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SendBulkEmailJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: EmailBatch, 1: EmailLog}
     */
    private function batchWithLog(int $pending = 1): array
    {
        SmtpSetting::factory()->create();

        $batch = EmailBatch::factory()->create([
            'subject'       => 'Hello {{ name }}',
            'body'          => '<p>Hi {{ name }}</p>',
            'total_emails'  => $pending,
            'pending_count' => $pending,
            'status'        => EmailBatch::STATUS_PROCESSING,
        ]);

        $log = EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'name'     => 'Ada',
            'email'    => 'ada@example.com',
        ]);

        return [$batch, $log];
    }

    public function test_a_successful_send_marks_the_log_sent_and_moves_the_counters(): void
    {
        Mail::fake();
        [$batch, $log] = $this->batchWithLog();

        (new SendBulkEmailJob($log->id))->handle(app(DynamicMailerService::class));

        $log->refresh();
        $batch->refresh();

        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertNotNull($log->sent_at);
        $this->assertSame(1, $batch->sent_count);
        $this->assertSame(0, $batch->pending_count);
        $this->assertSame(EmailBatch::STATUS_COMPLETED, $batch->status);

        Mail::assertSent(BulkEmailMessage::class);
    }

    public function test_the_recipient_name_is_substituted_into_subject_and_body(): void
    {
        Mail::fake();
        [, $log] = $this->batchWithLog();

        (new SendBulkEmailJob($log->id))->handle(app(DynamicMailerService::class));

        Mail::assertSent(BulkEmailMessage::class, function (BulkEmailMessage $mail) {
            return $mail->subjectLine === 'Hello Ada'
                && str_contains($mail->bodyHtml, 'Hi Ada');
        });
    }

    public function test_the_batch_stays_processing_while_rows_remain(): void
    {
        Mail::fake();
        [$batch, $log] = $this->batchWithLog(2);

        (new SendBulkEmailJob($log->id))->handle(app(DynamicMailerService::class));

        $batch->refresh();
        $this->assertSame(1, $batch->sent_count);
        $this->assertSame(1, $batch->pending_count);
        $this->assertSame(EmailBatch::STATUS_PROCESSING, $batch->status);
    }

    public function test_permanent_failure_marks_the_log_failed_with_the_error(): void
    {
        [$batch, $log] = $this->batchWithLog();

        (new SendBulkEmailJob($log->id))->failed(new RuntimeException('535 auth failed'));

        $log->refresh();
        $batch->refresh();

        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertSame('535 auth failed', $log->remarks);
        $this->assertSame(1, $batch->failed_count);
        $this->assertSame(0, $batch->pending_count);
        $this->assertSame(EmailBatch::STATUS_COMPLETED, $batch->status);
    }

    public function test_an_already_sent_log_is_not_sent_twice(): void
    {
        Mail::fake();
        [$batch, $log] = $this->batchWithLog();
        $log->update(['status' => EmailLog::STATUS_SENT]);

        (new SendBulkEmailJob($log->id))->handle(app(DynamicMailerService::class));

        Mail::assertNothingSent();
        $this->assertSame(0, $batch->fresh()->sent_count);
    }

    public function test_the_job_declares_its_retry_policy(): void
    {
        $job = new SendBulkEmailJob(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30, 60], $job->backoff);
        $this->assertIsInt($job->timeout);
    }
}
