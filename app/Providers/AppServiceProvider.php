<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MySQL 5.7 caps index keys at 767 bytes; utf8mb4 needs 191-char strings.
        Schema::defaultStringLength(191);

        $this->tuneSqlite();
    }

    /**
     * Apply the SQLite pragmas the app depends on. No-op on every other driver.
     *
     * - foreign_keys: SQLite does NOT enforce foreign keys by default, so
     *   without this the email_logs -> email_batches cascade silently does
     *   nothing and deleting a batch orphans its rows.
     * - journal_mode=WAL: lets a queue worker write while web requests read.
     *   Without it the two block each other and produce "database is locked".
     * - busy_timeout: wait rather than failing instantly on a held lock.
     */
    private function tuneSqlite(): void
    {
        try {
            $connection = DB::connection();

            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            $connection->statement('PRAGMA foreign_keys = ON');
            $connection->statement('PRAGMA busy_timeout = 5000');

            // WAL is a persistent property of the database file and is
            // unavailable for an in-memory database, so it is skipped there.
            if (! in_array($connection->getDatabaseName(), [':memory:', ''], true)) {
                $connection->statement('PRAGMA journal_mode = WAL');
            }
        } catch (Throwable) {
            // Never let pragma tuning take the application down: a missing or
            // unreachable database must still surface its own error later.
        }
    }
}
