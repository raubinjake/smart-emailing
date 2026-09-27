<?php

/**
 * Vercel serverless entrypoint.
 *
 * Every request that is not a static asset lands here and is handed to
 * Laravel's normal front controller. The bootstrapping below is the part that
 * differs from `public/index.php`: a serverless filesystem is read-only apart
 * from /tmp, so the framework's writable paths have to be relocated before the
 * application boots.
 */

// Laravel writes compiled views, caches and sessions to storage/. On Vercel the
// deployment is read-only, so point those at /tmp, which is writable and lives
// for the lifetime of the instance.
$tmp = '/tmp/storage';

foreach ([
    $tmp,
    $tmp . '/app',
    $tmp . '/app/public',
    $tmp . '/app/imports',
    $tmp . '/framework',
    $tmp . '/framework/cache',
    $tmp . '/framework/cache/data',
    $tmp . '/framework/sessions',
    $tmp . '/framework/testing',
    $tmp . '/framework/views',
    $tmp . '/logs',
] as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Read by bootstrap/app.php (see useStoragePath there).
putenv('LARAVEL_STORAGE_PATH=' . $tmp);
$_ENV['LARAVEL_STORAGE_PATH'] = $tmp;
$_SERVER['LARAVEL_STORAGE_PATH'] = $tmp;

require __DIR__ . '/../public/index.php';
