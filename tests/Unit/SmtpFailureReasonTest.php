<?php

namespace Tests\Unit;

use App\Support\SmtpFailureReason;
use Tests\TestCase;

class SmtpFailureReasonTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function errors(): array
    {
        return [
            'auth failure' => [
                'Failed to authenticate on SMTP server with username "bob@x.test" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "Expected response code 235 but got code "535", with message "535 Incorrect authentication data"."',
                'SMTP login was rejected — check the username and password on the active profile',
            ],
            'unknown mailbox' => [
                'Expected response code 250 but got code "550", with message "550 5.1.1 The email account that you tried to reach does not exist."',
                'Mailbox does not exist at that address',
            ],
            'mailbox full' => [
                'Expected response code 250 but got code "552", with message "552 5.2.2 Mailbox full"',
                'Recipient mailbox is full',
            ],
            'greylisted' => [
                'Expected response code 250 but got code "451", with message "451 4.7.1 Greylisted, try again later"',
                'Temporarily deferred by the receiving server — it asked us to retry later',
            ],
            'rate limited' => [
                'Expected response code 250 but got code "421", with message "421 4.7.0 Too many messages, slow down"',
                'Sending server is rate limiting us',
            ],
            'connection refused' => [
                'Connection could not be established with host "127.0.0.1:9": stream_socket_client(): Unable to connect',
                'Could not reach the mail server — check the host and port',
            ],
            'timeout' => [
                'Connection to "smtp.example.com" timed out',
                'The mail server did not respond in time',
            ],
            'tls' => [
                'SSL: error:1408F10B:SSL routines:ssl3_get_record:wrong version number',
                'Secure connection failed — check the encryption setting (TLS/SSL) and port',
            ],
            'no profile' => [
                'No active SMTP profile is configured.',
                'No active SMTP profile is configured',
            ],
            'unrecognised keeps the original' => [
                'Some entirely novel failure',
                'Some entirely novel failure',
            ],
        ];
    }

    /**
     * @dataProvider errors
     */
    public function test_it_translates_smtp_errors(string $raw, string $expected): void
    {
        $this->assertSame($expected, SmtpFailureReason::from($raw));
    }

    public function test_it_never_leaks_the_smtp_username(): void
    {
        $raw = 'Failed to authenticate on SMTP server with username "secret@internal.test" using the following authenticators: "LOGIN".';

        $this->assertStringNotContainsString('secret@internal.test', SmtpFailureReason::from($raw));
    }

    public function test_an_unrecognised_error_is_truncated(): void
    {
        $reason = SmtpFailureReason::from(str_repeat('x', 500));

        $this->assertLessThanOrEqual(191, mb_strlen($reason));
    }
}
