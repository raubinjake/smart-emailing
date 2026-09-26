<?php

namespace Tests\Unit;

use App\Jobs\SendBulkEmailJob;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Services\BatchDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BatchDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_one_job_per_pending_row_only(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create([
            'status'        => EmailBatch::STATUS_DRAFT,
            'total_emails'  => 3,
            'pending_count' => 2,
            'failed_count'  => 1,
        ]);

        EmailLog::factory()->count(2)->create([
            'batch_id' => $batch->id,
            'status'   => EmailLog::STATUS_PENDING,
        ]);
        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'status'   => EmailLog::STATUS_FAILED,
            'remarks'  => 'Invalid email syntax',
        ]);

        $queued = (new BatchDispatchService())->dispatch($batch, 'Subject', '<p>Body</p>');

        Queue::assertPushed(SendBulkEmailJob::class, 2);
        $this->assertSame(2, $queued);
    }

    public function test_it_persists_the_message_and_marks_the_batch_processing(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create(['status' => EmailBatch::STATUS_DRAFT]);
        EmailLog::factory()->create(['batch_id' => $batch->id]);

        (new BatchDispatchService())->dispatch($batch, 'Hi there', '<p>Body</p>');

        $batch->refresh();
        $this->assertSame('Hi there', $batch->subject);
        $this->assertSame('<p>Body</p>', $batch->body);
        $this->assertSame(EmailBatch::STATUS_PROCESSING, $batch->status);
    }

    public function test_a_batch_can_only_be_sent_once(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create(['status' => EmailBatch::STATUS_PROCESSING]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This batch has already been sent.');

        (new BatchDispatchService())->dispatch($batch, 'Subject', '<p>Body</p>');
    }

    public function test_a_completed_batch_cannot_be_resent(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create(['status' => EmailBatch::STATUS_COMPLETED]);

        $this->expectException(RuntimeException::class);

        (new BatchDispatchService())->dispatch($batch, 'Subject', '<p>Body</p>');

        Queue::assertNothingPushed();
    }

    public function test_it_queues_the_id_of_each_pending_log(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create(['status' => EmailBatch::STATUS_DRAFT]);
        $first  = EmailLog::factory()->create(['batch_id' => $batch->id]);
        $second = EmailLog::factory()->create(['batch_id' => $batch->id]);

        (new BatchDispatchService())->dispatch($batch, 'Subject', '<p>Body</p>');

        foreach ([$first->id, $second->id] as $id) {
            Queue::assertPushed(
                SendBulkEmailJob::class,
                fn (SendBulkEmailJob $job) => $job->emailLogId === $id,
            );
        }
    }

    public function test_a_batch_with_no_pending_rows_queues_nothing(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create(['status' => EmailBatch::STATUS_DRAFT]);
        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'status'   => EmailLog::STATUS_FAILED,
            'remarks'  => 'Invalid email syntax',
        ]);

        $queued = (new BatchDispatchService())->dispatch($batch, 'Subject', '<p>Body</p>');

        $this->assertSame(0, $queued);
        Queue::assertNothingPushed();

        // Nothing will ever settle this batch, so it must close immediately
        // rather than sit at `processing` forever.
        $this->assertSame(EmailBatch::STATUS_COMPLETED, $batch->fresh()->status);
    }
}
