<?php

namespace Tests\Feature;

use App\Models\EmailBatch;
use App\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_database_builds_the_schema_and_cascades(): void
    {
        $batch = EmailBatch::factory()->create();
        EmailLog::factory()->count(2)->create(['batch_id' => $batch->id]);

        $this->assertDatabaseCount('email_logs', 2);

        $batch->delete();

        // FK cascade must remove the logs along with the batch.
        $this->assertDatabaseCount('email_logs', 0);
    }
}
