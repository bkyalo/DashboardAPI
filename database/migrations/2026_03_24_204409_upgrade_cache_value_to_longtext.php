<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // mediumtext maxes at 16 MB; serialised 151k-row payloads exceed that.
    // longtext holds up to 4 GB.
    public function up(): void
    {
        DB::statement('ALTER TABLE cache MODIFY value longtext COLLATE utf8mb4_unicode_ci NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cache MODIFY value mediumtext COLLATE utf8mb4_unicode_ci NOT NULL');
    }
};
