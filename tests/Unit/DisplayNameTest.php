<?php

namespace Tests\Unit;

use App\Models\EmailLog;
use Tests\TestCase;

class DisplayNameTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function addresses(): array
    {
        return [
            'simple'            => ['robin@yopmail.com', 'Robin'],
            'alphanumeric'      => ['robinos36ty@gmail.com', 'Robinos36ty'],
            'dot separated'     => ['ada.lovelace@example.com', 'Ada Lovelace'],
            'underscore'        => ['alan_turing@example.com', 'Alan Turing'],
            'hyphen'            => ['grace-hopper@example.com', 'Grace Hopper'],
            'plus tag stripped' => ['robin+newsletter@gmail.com', 'Robin'],
            'mixed separators'  => ['jean.claude_van-damme@example.com', 'Jean Claude Van Damme'],
            'already cased'     => ['RobinKumar@example.com', 'RobinKumar'],
            'malformed'         => ['raubin@gmailll.com..,', 'Raubin'],
            'no at sign'        => ['not-an-email', 'Not An Email'],
            'digits collapse'   => ['robin2@example.com', 'Robin2'],
            'empty'             => ['', ''],
        ];
    }

    /**
     * @dataProvider addresses
     */
    public function test_it_derives_a_display_name_from_the_address(string $email, string $expected): void
    {
        $log = new EmailLog(['email' => $email]);

        $this->assertSame($expected, $log->displayName());
    }
}
