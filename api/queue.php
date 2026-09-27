<?php

/**
 * Cron-driven queue drain.
 *
 * Vercel has no long-running process, so `queue:work` cannot be left running.
 * Instead this route is hit once a minute by a Vercel Cron and drains whatever
 * is waiting, then exits. Jobs keep their retry policy, backoff and reporting —
 * the only difference from a persistent worker is that a batch may wait up to a
 * minute before it starts moving.
 *
 * Vercel signs cron invocations with CRON_SECRET; anything else is rejected so
 * the route cannot be used to burn function time.
 */

$tmp = '/tmp/storage';

foreach ([
    $tmp,
    $tmp . '/app',
    $tmp . '/framework',
    $tmp . '/framework/cache',
    $tmp . '/framework/cache/data',
    $tmp . '/framework/sessions',
    $tmp . '/framework/views',
    $tmp . '/logs',
] as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

putenv('LARAVEL_STORAGE_PATH=' . $tmp);
$_ENV['LARAVEL_STORAGE_PATH'] = $tmp;
$_SERVER['LARAVEL_STORAGE_PATH'] = $tmp;

$secret = getenv('CRON_SECRET') ?: '';
$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

// Vercel Cron sends `Authorization: Bearer <CRON_SECRET>`.
if ($secret === '' || ! hash_equals('Bearer ' . $secret, $header)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Leave headroom under the 60s function limit so the process exits cleanly
// rather than being killed mid-send.
$status = $kernel->call('queue:work', [
    '--stop-when-empty' => true,
    '--tries'           => 3,
    '--timeout'         => 45,
    '--max-time'        => 50,
    '--sleep'           => 0,
    '--quiet'           => true,
]);

header('Content-Type: application/json');
echo json_encode([
    'ok'     => $status === 0,
    'ran_at' => date('c'),
]);
