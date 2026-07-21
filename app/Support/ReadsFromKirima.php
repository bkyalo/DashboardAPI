<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only access to the legacy analytics database (kirima).
 *
 * Laravel app data stays on the default connection; reporting / FA 0_* tables
 * are queried through this connection only.
 */
trait ReadsFromKirima
{
    protected function kirima(): Connection
    {
        return DB::connection(config('database.kirima_connection', 'kirima'));
    }

    /**
     * Return " FORCE INDEX (name)" only when that index exists on the connected DB.
     *
     * Kirima has covering indexes that demo / other FA databases may lack.
     * Bare FORCE INDEX on a missing key hard-fails with SQLSTATE 1176.
     */
    protected function forceIndex(string $table, string $index): string
    {
        static $cache = [];

        $connection = $this->kirima();
        $key = $connection->getName()
            . '|' . ($connection->getDatabaseName() ?? '')
            . '|' . $table
            . '|' . $index;

        if (! array_key_exists($key, $cache)) {
            try {
                $row = $connection->selectOne(
                    'SELECT 1 AS ok
                     FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?
                       AND INDEX_NAME = ?
                     LIMIT 1',
                    [$table, $index]
                );
                $cache[$key] = $row !== null;
            } catch (\Throwable) {
                $cache[$key] = false;
            }
        }

        return $cache[$key] ? " FORCE INDEX ({$index})" : '';
    }
}
