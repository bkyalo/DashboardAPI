<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Covering indexes for the Farmer Collection Performance queries.
 *
 * Without these, the COUNT / paginated-data / export queries do full
 * table scans on 300 k+ rows and take 30–50 seconds each.
 *
 * idx_cover_po_date  (0_purch_orders)
 *   Drives the date-range scan and covers supplier_id, into_stock_location
 *   so the optimizer never touches the clustered index for those columns.
 *
 * idx_cover_farmer   (0_purch_order_details)
 *   Covers order_no (JOIN key), item_code (filter), plus every column
 *   projected in the SELECT — shift, route_id, unit_price, quantity_ordered.
 *   This turns the join into a pure index-to-index lookup with no heap reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Skip if already exist (safe to re-run)
        $this->addIndexIfMissing(
            '0_purch_orders',
            'idx_cover_po_date',
            '(`ord_date`, `order_no`, `supplier_id`, `into_stock_location`, `source_from`)'
        );

        $this->addIndexIfMissing(
            '0_purch_order_details',
            'idx_cover_farmer',
            '(`order_no`, `item_code`, `shift`, `route_id`, `unit_price`, `quantity_ordered`)'
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists('0_purch_orders',        'idx_cover_po_date');
        $this->dropIndexIfExists('0_purch_order_details', 'idx_cover_farmer');
    }

    private function addIndexIfMissing(string $table, string $index, string $columns): void
    {
        $exists = DB::selectOne("
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = ?
              AND INDEX_NAME   = ?
            LIMIT 1
        ", [$table, $index]);

        if (!$exists) {
            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$index}` {$columns}");
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        $exists = DB::selectOne("
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = ?
              AND INDEX_NAME   = ?
            LIMIT 1
        ", [$table, $index]);

        if ($exists) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }
};
