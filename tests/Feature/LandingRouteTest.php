<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/` is not a public welcome page — it is the auth-gated entry point that
 * drops an admin straight into the batch dashboard.
 */
class LandingRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_an_authenticated_user_is_sent_to_batches(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->get('/')->assertRedirect('/batches');
    }
}
