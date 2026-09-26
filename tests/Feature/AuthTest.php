<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_non_admin_user(): void
    {
        $this->post('/register', [
            'name'                  => 'Ada',
            'email'                 => 'ada@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect();

        $user = User::where('email', 'ada@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->is_admin);
    }

    public function test_registration_cannot_self_promote_to_admin(): void
    {
        $this->post('/register', [
            'name'                  => 'Mallory',
            'email'                 => 'mallory@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'is_admin'              => 1,
        ]);

        $this->assertFalse(User::where('email', 'mallory@example.com')->first()->is_admin);
    }

    public function test_registration_requires_a_confirmed_password(): void
    {
        $this->post('/register', [
            'name'                  => 'Ada',
            'email'                 => 'ada@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_bad_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_guests_are_redirected_from_batches(): void
    {
        $this->get('/batches')->assertRedirect('/login');
    }

    public function test_non_admins_get_403_on_batches(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/batches')->assertForbidden();
    }

    public function test_non_admins_get_403_on_smtp(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/smtp')->assertForbidden();
    }
}
