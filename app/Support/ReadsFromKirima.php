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
}
