<?php

namespace Tests\Unit;

use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Services\BatchImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RejectionReasonTest extends TestCase
{
    use RefreshDatabase;

    private function importRow(string $name, string $email): EmailLog
    {
        Storage::fake('local');

        $path = tempnam(sys_get_temp_dir(), 'rej') . '.csv';
        file_put_contents($path, "name,email\n\"{$name}\",\"{$email}\"\n");

        $batch = (new BatchImportService())->import(
            new UploadedFile($path, 'rows.csv', 'text/csv', null, true),
        );

        return $batch->logs()->first();
    }

    public function test_a_missing_name_says_so(): void
    {
        $this->assertSame('Name is missing', $this->importRow('', 'ada@example.com')->remarks);
    }

    public function test_a_missing_email_says_so(): void
    {
        $this->assertSame('Email address is missing', $this->importRow('Ada', '')->remarks);
    }

    public function test_a_missing_at_sign_says_so(): void
    {
        $this->assertSame('Not an email address (no @ sign)', $this->importRow('Ada', 'ada.example.com')->remarks);
    }

    public function test_a_malformed_domain_says_so(): void
    {
        $this->assertSame(
            'Malformed domain "gmailll.com..,"',
            $this->importRow('Rbn', 'raubin@gmailll.com..,')->remarks,
        );
    }

    public function test_a_space_inside_the_address_says_so(): void
    {
        $this->assertSame('Address contains a space', $this->importRow('Ada', 'ada lovelace@example.com')->remarks);
    }

    public function test_an_overlong_value_says_which_field(): void
    {
        $long = str_repeat('A', 300);
        $this->assertSame('Name is longer than 191 characters', $this->importRow($long, 'ada@example.com')->remarks);
    }

    public function test_a_valid_row_has_no_remark(): void
    {
        $log = $this->importRow('Ada', 'ada@example.com');

        $this->assertSame(EmailLog::STATUS_PENDING, $log->status);
        $this->assertNull($log->remarks);
    }

    public function test_a_duplicate_address_is_flagged_but_still_sent(): void
    {
        Storage::fake('local');

        $path = tempnam(sys_get_temp_dir(), 'dup') . '.csv';
        file_put_contents($path, "name,email\nAda,ada@example.com\nAda Again,ada@example.com\n");

        $batch = (new BatchImportService())->import(
            new UploadedFile($path, 'dup.csv', 'text/csv', null, true),
        );

        $second = $batch->logs()->orderBy('id')->get()->last();

        // Still pending — a duplicate is a warning, not a rejection.
        $this->assertSame(EmailLog::STATUS_PENDING, $second->status);
        $this->assertSame('Duplicate address in this file', $second->remarks);
        $this->assertSame(2, $batch->pending_count);
    }
}
