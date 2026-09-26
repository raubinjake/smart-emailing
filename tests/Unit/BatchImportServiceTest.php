<?php

namespace Tests\Unit;

use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Services\BatchImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BatchImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import') . '.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'recipients.csv', 'text/csv', null, true);
    }

    public function test_it_creates_a_draft_batch_with_one_log_per_row(): void
    {
        Storage::fake('local');

        $file = $this->csv("name,email\nAda,ada@example.com\nBob,bob@example.com\n");

        $batch = (new BatchImportService())->import($file);

        $this->assertSame(EmailBatch::STATUS_DRAFT, $batch->status);
        $this->assertSame(2, $batch->total_emails);
        $this->assertSame(2, $batch->pending_count);
        $this->assertSame(0, $batch->failed_count);
        $this->assertCount(2, $batch->logs);
    }

    public function test_invalid_rows_are_logged_as_failed_and_still_appear_in_the_report(): void
    {
        Storage::fake('local');

        $file = $this->csv("name,email\nAda,ada@example.com\nBob,not-an-email\nCarol,\n");

        $batch = (new BatchImportService())->import($file);

        $this->assertSame(3, $batch->total_emails, 'every row of the file must be recorded');
        $this->assertSame(1, $batch->pending_count);
        $this->assertSame(2, $batch->failed_count);

        $failed = $batch->logs()->where('status', EmailLog::STATUS_FAILED)->get();
        $this->assertCount(2, $failed);
        $this->assertSame('Invalid email syntax', $failed->first()->remarks);
    }

    public function test_rows_missing_a_name_are_rejected(): void
    {
        Storage::fake('local');

        $file = $this->csv("name,email\n,dave@example.com\n");

        $batch = (new BatchImportService())->import($file);

        $this->assertSame(1, $batch->failed_count);
        $this->assertSame(0, $batch->pending_count);
    }

    public function test_fully_blank_rows_are_ignored(): void
    {
        Storage::fake('local');

        $file = $this->csv("name,email\nAda,ada@example.com\n,\n");

        $batch = (new BatchImportService())->import($file);

        $this->assertSame(1, $batch->total_emails);
    }

    public function test_it_records_the_original_file_name_and_a_uuid(): void
    {
        Storage::fake('local');

        $file = $this->csv("name,email\nAda,ada@example.com\n");

        $batch = (new BatchImportService())->import($file);

        $this->assertSame('recipients.csv', $batch->file_name);
        $this->assertNotEmpty($batch->batch_uuid);
        $this->assertNotEmpty($batch->stored_path);
    }

    public function test_values_are_trimmed(): void
    {
        Storage::fake('local');

        $file = $this->csv("name,email\n  Ada  ,  ada@example.com  \n");

        $batch = (new BatchImportService())->import($file);

        $log = $batch->logs()->first();
        $this->assertSame('Ada', $log->name);
        $this->assertSame('ada@example.com', $log->email);
        $this->assertSame(EmailLog::STATUS_PENDING, $log->status);
    }
}
