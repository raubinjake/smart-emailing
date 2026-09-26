<?php

namespace App\Services;

use App\Models\SmtpSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Builds a mail transport at runtime from a database-stored SMTP profile,
 * so credentials never live in .env.
 */
class DynamicMailerService
{
    public const MAILER = 'dynamic_smtp';

    /**
     * Register the given profile as the `dynamic_smtp` mailer.
     *
     * @param  SmtpSetting  $smtp  the profile to configure
     * @return string the mailer name to pass to Mail::mailer()
     */
    public function configure(SmtpSetting $smtp): string
    {
        Config::set('mail.mailers.' . self::MAILER, [
            'transport'  => 'smtp',
            'host'       => $smtp->host,
            'port'       => $smtp->port,
            'encryption' => $smtp->encryption,
            'username'   => $smtp->username,
            'password'   => Crypt::decryptString($smtp->password),
            'timeout'    => null,
        ]);

        Config::set('mail.from.address', $smtp->from_address);
        Config::set('mail.from.name', $smtp->from_name);

        return self::MAILER;
    }

    /**
     * Configure the mailer from whichever profile is currently active.
     *
     * @return string the mailer name to pass to Mail::mailer()
     * @throws RuntimeException when no profile is active
     */
    public function configureActive(): string
    {
        $smtp = SmtpSetting::active();

        if ($smtp === null) {
            throw new RuntimeException('No active SMTP profile is configured.');
        }

        return $this->configure($smtp);
    }
}
