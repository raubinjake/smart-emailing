<?php

namespace Tests\Feature;

use App\Mail\BulkEmailMessage;
use App\Models\SmtpSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SmtpSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'         => 'Primary',
            'host'         => 'smtp.example.com',
            'port'         => 587,
            'username'     => 'mailer@example.com',
            'password'     => 'plaintext',
            'encryption'   => 'tls',
            'from_address' => 'noreply@example.com',
            'from_name'    => 'Smart Emailing',
        ], $overrides);
    }

    public function test_storing_a_profile_encrypts_the_password(): void
    {
        $this->actingAs($this->admin)
            ->post('/smtp', $this->payload())
            ->assertRedirect(route('smtp.index'));

        $smtp = SmtpSetting::first();
        $this->assertNotSame('plaintext', $smtp->password);
        $this->assertSame('plaintext', Crypt::decryptString($smtp->password));
    }

    public function test_the_first_profile_is_activated_automatically(): void
    {
        $this->actingAs($this->admin)->post('/smtp', $this->payload());

        $this->assertTrue(SmtpSetting::first()->is_active);
    }

    public function test_a_later_profile_does_not_steal_the_active_flag(): void
    {
        SmtpSetting::factory()->create(['name' => 'Existing', 'is_active' => true]);

        $this->actingAs($this->admin)->post('/smtp', $this->payload(['name' => 'Second']));

        $this->assertFalse(SmtpSetting::where('name', 'Second')->first()->is_active);
        $this->assertSame(1, SmtpSetting::where('is_active', true)->count());
    }

    public function test_storing_requires_the_mandatory_fields(): void
    {
        $this->actingAs($this->admin)
            ->post('/smtp', ['name' => '', 'host' => '', 'port' => '', 'from_address' => 'not-an-email'])
            ->assertSessionHasErrors(['name', 'host', 'port', 'username', 'password', 'from_address', 'from_name']);
    }

    public function test_activating_a_profile_deactivates_the_others(): void
    {
        $first  = SmtpSetting::factory()->create(['is_active' => true]);
        $second = SmtpSetting::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin)
            ->post(route('smtp.activate', $second))
            ->assertRedirect(route('smtp.index'));

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertSame(1, SmtpSetting::where('is_active', true)->count());
    }

    public function test_updating_without_a_password_keeps_the_existing_one(): void
    {
        $smtp = SmtpSetting::factory()->create(['password' => Crypt::encryptString('original')]);

        $this->actingAs($this->admin)
            ->put(route('smtp.update', $smtp), $this->payload(['name' => 'Renamed', 'password' => '']))
            ->assertRedirect(route('smtp.index'));

        $smtp->refresh();
        $this->assertSame('Renamed', $smtp->name);
        $this->assertSame('original', Crypt::decryptString($smtp->password));
    }

    public function test_updating_with_a_password_replaces_it(): void
    {
        $smtp = SmtpSetting::factory()->create(['password' => Crypt::encryptString('original')]);

        $this->actingAs($this->admin)
            ->put(route('smtp.update', $smtp), $this->payload(['password' => 'rotated']));

        $this->assertSame('rotated', Crypt::decryptString($smtp->fresh()->password));
    }

    public function test_a_test_message_is_sent_through_the_chosen_profile(): void
    {
        Mail::fake();
        $smtp = SmtpSetting::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('smtp.test', $smtp), ['email' => 'check@example.com'])
            ->assertRedirect(route('smtp.index'))
            ->assertSessionHas('status');

        Mail::assertSent(BulkEmailMessage::class);
    }

    public function test_a_test_message_requires_a_valid_address(): void
    {
        Mail::fake();
        $smtp = SmtpSetting::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('smtp.test', $smtp), ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_deleting_a_profile_removes_it(): void
    {
        $smtp = SmtpSetting::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('smtp.destroy', $smtp))
            ->assertRedirect(route('smtp.index'));

        $this->assertDatabaseCount('smtp_settings', 0);
    }

    public function test_non_admins_cannot_reach_smtp_settings(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/smtp')->assertForbidden();
        $this->actingAs($user)->post('/smtp', $this->payload())->assertForbidden();

        $this->assertDatabaseCount('smtp_settings', 0);
    }
}
