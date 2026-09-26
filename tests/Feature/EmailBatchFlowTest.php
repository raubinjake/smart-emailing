<?php

namespace Tests\Feature;

use App\Jobs\SendBulkEmailJob;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailBatchFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'flow') . '.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'recipients.csv', 'text/csv', null, true);
    }

    public function test_upload_parses_the_file_and_redirects_to_compose(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->admin)->post('/batches/upload', [
            'file' => $this->csv("name,email\nAda,ada@example.com\nBob,bad\n"),
        ]);

        $batch = EmailBatch::first();
        $this->assertNotNull($batch);
        $response->assertRedirect(route('batches.compose', $batch));

        $this->assertSame(2, $batch->total_emails);
        $this->assertSame(1, $batch->pending_count);
        $this->assertSame(1, $batch->failed_count);
        $this->assertSame(EmailBatch::STATUS_DRAFT, $batch->status);
    }

    public function test_upload_rejects_a_non_spreadsheet(): void
    {
        Storage::fake('local');

        $bad = UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf');

        $this->actingAs($this->admin)
            ->post('/batches/upload', ['file' => $bad])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('email_batches', 0);
    }

    public function test_upload_requires_a_file(): void
    {
        $this->actingAs($this->admin)
            ->post('/batches/upload', [])
            ->assertSessionHasErrors('file');
    }

    public function test_send_queues_jobs_and_marks_the_batch_processing(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create(['pending_count' => 1, 'total_emails' => 1]);
        EmailLog::factory()->create(['batch_id' => $batch->id]);

        $this->actingAs($this->admin)
            ->post(route('batches.send', $batch), [
                'subject' => 'Hello',
                'body'    => '<p>Hi {{ name }}</p>',
            ])
            ->assertRedirect(route('batches.show', $batch));

        Queue::assertPushed(SendBulkEmailJob::class, 1);

        $batch->refresh();
        $this->assertSame(EmailBatch::STATUS_PROCESSING, $batch->status);
        $this->assertSame('Hello', $batch->subject);
    }

    public function test_send_requires_a_subject_and_body(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('batches.send', $batch), ['subject' => '', 'body' => ''])
            ->assertSessionHasErrors(['subject', 'body']);

        Queue::assertNothingPushed();
        $this->assertSame(EmailBatch::STATUS_DRAFT, $batch->fresh()->status);
    }

    public function test_a_batch_cannot_be_sent_twice(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create(['status' => EmailBatch::STATUS_PROCESSING]);

        $this->actingAs($this->admin)
            ->post(route('batches.send', $batch), ['subject' => 'Hello', 'body' => '<p>Hi</p>'])
            ->assertRedirect(route('batches.show', $batch))
            ->assertSessionHas('error', 'This batch has already been sent.');

        Queue::assertNothingPushed();
    }

    public function test_deleting_a_batch_removes_its_logs(): void
    {
        $batch = EmailBatch::factory()->create();
        EmailLog::factory()->count(2)->create(['batch_id' => $batch->id]);

        $this->actingAs($this->admin)
            ->delete(route('batches.destroy', $batch))
            ->assertRedirect(route('batches.index'));

        $this->assertDatabaseCount('email_batches', 0);
        $this->assertDatabaseCount('email_logs', 0);
    }

    public function test_deleting_a_batch_removes_its_stored_upload(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)->post('/batches/upload', [
            'file' => $this->csv("name,email\nAda,ada@example.com\n"),
        ]);

        $batch = EmailBatch::first();
        $path  = $batch->stored_path;
        Storage::assertExists($path);

        $this->actingAs($this->admin)->delete(route('batches.destroy', $batch));

        Storage::assertMissing($path);
    }

    public function test_the_sample_template_downloads(): void
    {
        $response = $this->actingAs($this->admin)->get(route('batches.sample'));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type') ?? '',
        );
    }

    public function test_the_report_exports_as_a_file(): void
    {
        $batch = EmailBatch::factory()->create();
        EmailLog::factory()->create(['batch_id' => $batch->id]);

        $this->actingAs($this->admin)
            ->get(route('batches.export', $batch))
            ->assertOk();
    }

    public function test_a_non_admin_cannot_upload(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post('/batches/upload', ['file' => $this->csv("name,email\nAda,ada@example.com\n")])
            ->assertForbidden();

        $this->assertDatabaseCount('email_batches', 0);
    }
}
