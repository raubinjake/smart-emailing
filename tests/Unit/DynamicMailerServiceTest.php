<?php

namespace Tests\Unit;

use App\Models\SmtpSetting;
use App\Services\DynamicMailerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DynamicMailerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_configures_a_transport_from_the_active_profile(): void
    {
        $smtp = SmtpSetting::factory()->create([
            'host'     => 'smtp.mailer.test',
            'port'     => 2525,
            'username' => 'bob@mailer.test',
            'password' => Crypt::encryptString('s3cret'),
        ]);

        (new DynamicMailerService())->configure($smtp);

        $this->assertSame('smtp', Config::get('mail.mailers.dynamic_smtp.transport'));
        $this->assertSame('smtp.mailer.test', Config::get('mail.mailers.dynamic_smtp.host'));
        $this->assertSame(2525, Config::get('mail.mailers.dynamic_smtp.port'));
        $this->assertSame('s3cret', Config::get('mail.mailers.dynamic_smtp.password'));
    }

    public function test_it_sets_the_from_identity(): void
    {
        $smtp = SmtpSetting::factory()->create([
            'from_address' => 'hello@mailer.test',
            'from_name'    => 'Mailer Test',
        ]);

        (new DynamicMailerService())->configure($smtp);

        $this->assertSame('hello@mailer.test', Config::get('mail.from.address'));
        $this->assertSame('Mailer Test', Config::get('mail.from.name'));
    }

    public function test_configure_returns_the_mailer_name(): void
    {
        $smtp = SmtpSetting::factory()->create();

        $this->assertSame('dynamic_smtp', (new DynamicMailerService())->configure($smtp));
    }

    public function test_configure_active_uses_the_active_profile(): void
    {
        SmtpSetting::factory()->create(['name' => 'Old', 'host' => 'old.test', 'is_active' => false]);
        SmtpSetting::factory()->create(['name' => 'Current', 'host' => 'current.test', 'is_active' => true]);

        (new DynamicMailerService())->configureActive();

        $this->assertSame('current.test', Config::get('mail.mailers.dynamic_smtp.host'));
    }

    public function test_reconfiguring_actually_rebuilds_the_transport(): void
    {
        $a = SmtpSetting::factory()->create(['host' => 'smtp-a.example.com', 'is_active' => true]);
        (new DynamicMailerService())->configure($a);
        $this->assertStringContainsString('smtp-a', (string) Mail::mailer('dynamic_smtp')->getSymfonyTransport());

        $b = SmtpSetting::factory()->create(['host' => 'smtp-b.example.com', 'is_active' => true]);
        (new DynamicMailerService())->configure($b);

        // MailManager caches resolved mailers; without purging, a long-running
        // worker would keep sending through profile A forever.
        $this->assertStringContainsString(
            'smtp-b',
            (string) Mail::mailer('dynamic_smtp')->getSymfonyTransport(),
        );
    }

    public function test_it_throws_when_no_profile_is_active(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active SMTP profile is configured.');

        (new DynamicMailerService())->configureActive();
    }
}
