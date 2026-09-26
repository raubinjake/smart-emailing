<?php

namespace Tests\Feature;

use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Models\SmtpSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewRenderingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'name' => 'Ada']);
    }

    public function test_the_login_and_register_pages_render(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in');
        $this->get('/register')->assertOk()->assertSee('Register');
    }

    public function test_the_batch_index_renders_with_and_without_batches(): void
    {
        $this->actingAs($this->admin)->get(route('batches.index'))->assertOk();

        EmailBatch::factory()->create(['file_name' => 'list.xlsx']);

        $this->actingAs($this->admin)
            ->get(route('batches.index'))
            ->assertOk()
            ->assertSee('list.xlsx')
            ->assertSee('Ada');
    }

    public function test_the_compose_page_shows_the_matching_record_count(): void
    {
        $batch = EmailBatch::factory()->create(['total_emails' => 3, 'pending_count' => 3]);

        $this->actingAs($this->admin)
            ->get(route('batches.compose', $batch))
            ->assertOk()
            ->assertSee('Matching Records: 3')
            ->assertSee('SEND BULK EMAIL');
    }

    public function test_the_compose_page_warns_when_no_rows_matched(): void
    {
        $batch = EmailBatch::factory()->create(['total_emails' => 0, 'pending_count' => 0]);

        $this->actingAs($this->admin)
            ->get(route('batches.compose', $batch))
            ->assertOk()
            ->assertSee('Matching Records: 0')
            ->assertSee('name', false)
            ->assertSee('email', false);
    }

    public function test_the_compose_page_reports_rejected_rows(): void
    {
        $batch = EmailBatch::factory()->create([
            'total_emails'  => 5,
            'pending_count' => 3,
            'failed_count'  => 2,
        ]);

        $this->actingAs($this->admin)
            ->get(route('batches.compose', $batch))
            ->assertOk()
            ->assertSee('Matching Records: 3')
            ->assertSee('2');
    }

    public function test_the_report_page_lists_every_row_including_invalid_ones(): void
    {
        $batch = EmailBatch::factory()->create(['total_emails' => 2, 'sent_count' => 1]);

        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'email'    => 'ada@example.com',
            'status'   => EmailLog::STATUS_SENT,
            'sent_at'  => now(),
        ]);
        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'email'    => 'bad-address',
            'status'   => EmailLog::STATUS_FAILED,
            'remarks'  => 'Invalid email syntax',
        ]);

        $this->actingAs($this->admin)
            ->get(route('batches.show', $batch))
            ->assertOk()
            ->assertSee('ada@example.com')
            ->assertSee('bad-address')
            ->assertSee('Invalid email syntax');
    }

    public function test_the_report_page_filters_by_status(): void
    {
        $batch = EmailBatch::factory()->create();

        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'email'    => 'sent@example.com',
            'status'   => EmailLog::STATUS_SENT,
        ]);
        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'email'    => 'failed@example.com',
            'status'   => EmailLog::STATUS_FAILED,
        ]);

        $this->actingAs($this->admin)
            ->get(route('batches.show', ['batch' => $batch, 'status' => 'failed']))
            ->assertOk()
            ->assertSee('failed@example.com')
            ->assertDontSee('sent@example.com');
    }

    public function test_the_smtp_pages_render(): void
    {
        $this->actingAs($this->admin)->get(route('smtp.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('smtp.create'))->assertOk();

        $smtp = SmtpSetting::factory()->create(['name' => 'Primary']);

        $this->actingAs($this->admin)
            ->get(route('smtp.index'))
            ->assertOk()
            ->assertSee('Primary');

        $this->actingAs($this->admin)
            ->get(route('smtp.edit', $smtp))
            ->assertOk()
            ->assertSee('Primary');
    }

    public function test_the_smtp_form_never_renders_the_stored_password(): void
    {
        $smtp = SmtpSetting::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('smtp.edit', $smtp))
            ->assertOk()
            ->assertDontSee($smtp->password, false)
            ->assertDontSee('secret');
    }

    public function test_the_compose_page_loads_the_vendored_tinymce(): void
    {
        $batch = EmailBatch::factory()->create(['pending_count' => 2]);

        $this->actingAs($this->admin)
            ->get(route('batches.compose', $batch))
            ->assertOk()
            ->assertSee('vendor/tinymce/tinymce.min.js', false)
            ->assertSee("license_key: 'gpl'", false);
    }
}
