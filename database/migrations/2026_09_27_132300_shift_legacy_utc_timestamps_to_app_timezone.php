<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rows written before APP_TIMEZONE was set hold UTC wall-clock values, so an
 * IST-configured app renders them 5h30m early. Shift those rows once to the
 * application timezone; rows written afterwards are already correct.
 *
 * The cutoff is the moment the setting changed — anything at or after it was
 * written in the new timezone and must be left alone.
 */
return new class extends Migration
{
    /** Rows created before this instant were written in UTC. */
    private const CUTOFF = '2026-09-27 13:00:00';

    /**
     * @return array<string, array<int, string>> table => datetime columns
     */
    private function targets(): array
    {
        return [
            'email_logs'    => ['created_at', 'updated_at', 'sent_at'],
            'email_batches' => ['created_at', 'updated_at'],
        ];
    }

    /**
     * Offset in seconds between UTC and the app timezone at the cutoff.
     */
    private function offsetSeconds(): int
    {
        $appTz = config('app.timezone');

        if ($appTz === 'UTC') {
            return 0;
        }

        $utc   = new DateTime(self::CUTOFF, new DateTimeZone('UTC'));
        $local = new DateTime(self::CUTOFF, new DateTimeZone($appTz));

        return $utc->getTimestamp() - $local->getTimestamp();
    }

    public function up(): void
    {
        $this->shift($this->offsetSeconds());
    }

    public function down(): void
    {
        $this->shift(-$this->offsetSeconds());
    }

    /**
     * Move every legacy timestamp by the given number of seconds.
     *
     * @param  int  $seconds  positive moves forward, negative moves back
     */
    private function shift(int $seconds): void
    {
        if ($seconds === 0) {
            return;
        }

        $connection = DB::connection();
        $grammar    = $connection->getQueryGrammar();
        $isSqlite   = $connection->getDriverName() === 'sqlite';

        foreach ($this->targets() as $table => $columns) {
            $sets = [];

            foreach ($columns as $column) {
                // Identifiers are quoted by the connection's own grammar:
                // backticks on MySQL, double quotes on SQLite.
                $quoted = $grammar->wrap($column);

                // NULL stays NULL — an unsent row has no sent_at to shift.
                // Both forms return NULL for a NULL input.
                $sets[] = $isSqlite
                    ? sprintf("%s = datetime(%s, '%+d seconds')", $quoted, $quoted, $seconds)
                    : sprintf('%s = DATE_ADD(%s, INTERVAL %d SECOND)', $quoted, $quoted, $seconds);
            }

            DB::statement(sprintf(
                'UPDATE %s SET %s WHERE %s < ?',
                $grammar->wrapTable($table),
                implode(', ', $sets),
                $grammar->wrap('created_at'),
            ), [self::CUTOFF]);
        }
    }
};
