<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the first admin. Registration always creates non-admin users, so
 * without this there is no way into the dashboard on a fresh install.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@smart-emailing.test'],
            [
                'name'     => 'Admin',
                'password' => Hash::make('password'),
                'is_admin' => true,
            ],
        );
    }
}
