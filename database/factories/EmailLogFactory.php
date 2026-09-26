<?php

namespace Database\Factories;

use App\Models\EmailBatch;
use App\Models\EmailLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_id' => EmailBatch::factory(),
            'name'     => $this->faker->name(),
            'email'    => $this->faker->safeEmail(),
            'status'   => EmailLog::STATUS_PENDING,
            'attempts' => 0,
            'remarks'  => null,
            'sent_at'  => null,
        ];
    }
}
