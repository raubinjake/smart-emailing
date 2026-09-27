<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => \App\Http\Middleware\IsAdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

// A serverless deployment is read-only apart from /tmp, so the entrypoint
// relocates Laravel's writable paths there and sets this variable. Everywhere
// else it is unset and storage/ is used as normal.
if ($storagePath = env('LARAVEL_STORAGE_PATH')) {
    $app->useStoragePath($storagePath);
}

return $app;
