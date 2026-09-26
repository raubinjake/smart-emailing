<?php

namespace Database\Factories;

use App\Models\EmailBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EmailBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_uuid'    => (string) Str::uuid(),
            'file_name'     => 'recipients.xlsx',
            'subject'       => null,
            'body'          => null,
            'total_emails'  => 0,
            'sent_count'    => 0,
            'failed_count'  => 0,
            'pending_count' => 0,
            'status'        => EmailBatch::STATUS_DRAFT,
        ];
    }
}
