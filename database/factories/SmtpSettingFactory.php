<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

class SmtpSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'         => 'Primary',
            'host'         => 'smtp.example.com',
            'port'         => 587,
            'username'     => 'mailer@example.com',
            'password'     => Crypt::encryptString('secret'),
            'encryption'   => 'tls',
            'from_address' => 'noreply@example.com',
            'from_name'    => 'Smart Emailing',
            'is_active'    => true,
        ];
    }
}
