<?php

namespace App\Support;

/**
 * Turns a raw mail-transport exception into a reason a person can act on.
 *
 * Symfony's SMTP errors are written for developers — they quote response
 * codes, authenticator names, and the sending username. The batch report is
 * read by whoever is running the campaign, so it needs the short version:
 * what went wrong and what to change.
 */
class SmtpFailureReason
{
    /** Keeps a remark inside the email_logs column width. */
    private const MAX_LENGTH = 191;

    /**
     * Patterns tried in order; the first match wins.
     *
     * Ordering matters — an auth failure also carries a response code, so it
     * has to be recognised before the generic code matching.
     *
     * @return array<int, array{0: string, 1: string}> regex => reason
     */
    private static function rules(): array
    {
        return [
            ['/failed to authenticate/i', 'SMTP login was rejected — check the username and password on the active profile'],
            ['/no active smtp profile/i', 'No active SMTP profile is configured'],

            // Connection-level problems, before any SMTP conversation happens.
            ['/connection could not be established|unable to connect|connection refused/i', 'Could not reach the mail server — check the host and port'],
            ['/timed out|timeout/i', 'The mail server did not respond in time'],
            ['/ssl|tls|certificate/i', 'Secure connection failed — check the encryption setting (TLS/SSL) and port'],
            ['/getaddrinfo|name or service not known|could not resolve/i', 'Could not resolve the mail server hostname'],

            // Permanent 5xx rejections.
            ['/\b(550|553)\b.*(does not exist|no such user|unknown|invalid|not found)/is', 'Mailbox does not exist at that address'],
            ['/\b552\b|mailbox full|quota exceeded|over quota/i', 'Recipient mailbox is full'],
            ['/\b(550|554)\b.*(spam|blocked|blacklist|reputation|policy)/is', 'Rejected by the receiving server as spam or policy-blocked'],
            ['/\b551\b|relay(ing)? denied|not permitted to relay/i', 'The server refused to relay to that address'],
            ['/\b(550|553)\b/', 'Rejected by the receiving server'],

            // Temporary 4xx deferrals.
            ['/\b421\b|too many|rate limit|slow down|throttl/i', 'Sending server is rate limiting us'],
            ['/\b(450|451|452)\b|greylist/i', 'Temporarily deferred by the receiving server — it asked us to retry later'],
        ];
    }

    /**
     * @param  string  $error  the raw exception message
     * @return string a reason fit for the batch report
     */
    public static function from(string $error): string
    {
        foreach (self::rules() as [$pattern, $reason]) {
            if (preg_match($pattern, $error) === 1) {
                return $reason;
            }
        }

        // Nothing matched: keep the original so the detail is not lost, but
        // trim it to the column width.
        return mb_strimwidth(trim($error), 0, self::MAX_LENGTH, '…');
    }
}
