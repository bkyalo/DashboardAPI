<?php

namespace App\Http\Controllers\Inventory;
use App\Support\ReadsFromKirima;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InventoryMovementReportController extends Controller
{
    use ReadsFromKirima;

    private const P = '0_';

    private function isLive(string $to): bool
    {
        return $to >= date('Y-m-d');
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from'     => 'required|date',
            'to'       => 'required|date|after_or_equal:from',
            'category' => 'nullable|integer',
            'location' => 'nullable|string|max:30',
        ]);

        $from     = date('Y-m-d', strtotime($request->from));
        $to       = date('Y-m-d', strtotime($request->to));
        $category = $request->category ? (int) $request->category : 0;
        $location = $request->location ?? '';

        $cacheKey = "inv_movement_report_{$from}_{$to}_{$category}_" . ($location ?: 'all');
        if ($cached = Cache::get($cacheKey)) {
            return ApiResponse::success($cached, 'Inventory movement report retrieved');
        }

        $P = self::P;

        // ── Location JOIN condition ────────────────────────────────────────────
        $locationJoin = $location !== ''
            ? "AND sm.loc_code = " . $this->kirima()->getPdo()->quote($location)
            : '';

        // ── Category WHERE condition ──────────────────────────────────────────
        $categoryWhere = $category > 0
            ? "AND i.category_id = " . (int) $category
            : '';

        // Single query: conditional aggregation gives all columns per item.
        // opening  = SUM(qty) where tran_date < from
        // qty_in   = SUM(qty) where in range AND qty > 0
        // qty_out  = SUM(-qty) where in range AND qty < 0
        // sales    = SUM(-qty) where in range AND type=13 AND qty < 0
        // mnfg     = SUM(-qty) where in range AND type IN (28,29) AND qty < 0
        // movt     = SUM(-qty) where in range AND type=16 AND qty < 0
        // balance  = SUM(qty) where tran_date <= to  (= opening + net period)
        $sql = "
            SELECT
                i.stock_id,
                i.description                                                           AS name,
                i.category_id,
                i.units,
                COALESCE(cat.description, '')                                           AS category_description,
                ROUND(COALESCE(SUM(CASE WHEN sm.tran_date <  '{$from}'                                                            THEN sm.qty  ELSE 0 END), 0), 4) AS opening,
                ROUND(COALESCE(SUM(CASE WHEN sm.tran_date BETWEEN '{$from}' AND '{$to}' AND sm.qty >  0                           THEN sm.qty  ELSE 0 END), 0), 4) AS qty_in,
                ROUND(COALESCE(SUM(CASE WHEN sm.tran_date BETWEEN '{$from}' AND '{$to}' AND sm.qty <  0                           THEN -sm.qty ELSE 0 END), 0), 4) AS qty_out,
                ROUND(COALESCE(SUM(CASE WHEN sm.tran_date BETWEEN '{$from}' AND '{$to}' AND sm.type = 13  AND sm.qty < 0          THEN -sm.qty ELSE 0 END), 0), 4) AS sales,
                ROUND(COALESCE(SUM(CASE WHEN sm.tran_date BETWEEN '{$from}' AND '{$to}' AND sm.type IN (28,29) AND sm.qty < 0     THEN -sm.qty ELSE 0 END), 0), 4) AS mnfg,
                ROUND(COALESCE(SUM(CASE WHEN sm.tran_date BETWEEN '{$from}' AND '{$to}' AND sm.type = 16  AND sm.qty < 0          THEN -sm.qty ELSE 0 END), 0), 4) AS movt,
                ROUND(COALESCE(SUM(CASE WHEN sm.tran_date <= '{$to}'                                                               THEN sm.qty  ELSE 0 END), 0), 4) AS balance
            FROM {$P}stock_master i
            LEFT JOIN {$P}stock_category cat ON cat.category_id = i.category_id
            LEFT JOIN {$P}stock_moves sm ON sm.stock_id = i.stock_id {$locationJoin}
            WHERE i.mb_flag NOT IN ('D', 'F')
              {$categoryWhere}
            GROUP BY i.stock_id, i.description, i.category_id, i.units, cat.description
            ORDER BY i.category_id, i.stock_id
        ";

        try {
            $rows = $this->kirima()->select($sql);

            // Add qty_available = opening + qty_in (mirrors PHP report's "Qty Av.")
            foreach ($rows as $row) {
                $row->qty_available = round($row->opening + $row->qty_in, 4);
            }

            $ttl = $this->isLive($to) ? 300 : 86400 * 30;
            Cache::put($cacheKey, $rows, $ttl);

            return ApiResponse::success($rows, 'Inventory movement report retrieved');

        } catch (\Throwable $e) {
            return ApiResponse::serverError('Inventory movement report failed: ' . $e->getMessage());
        }
    }

    /**
     * Current RAW MILK quantity per store location.
     * Always reflects live stock (QOH = SUM of all movements to date).
     * Cached 5 minutes — refreshes automatically on next request.
     */
    public function milkByLocation(): JsonResponse
    {
        $cacheKey = 'inv_milk_by_location_' . date('Y-m-d-H');
        if ($cached = Cache::get($cacheKey)) {
            return ApiResponse::success($cached, 'Milk by location retrieved');
        }

        $P   = self::P;
        $today = date('Y-m-d');

        // QOH per location for all items in category 1 (RAW MILK).
        // Grouping by item and location lets us sum across all milk stock_ids.
        $sql = "
            SELECT
                l.loc_code,
                l.location_name,
                ROUND(COALESCE(SUM(sm.qty), 0), 3) AS current_qty
            FROM {$P}locations l
            LEFT JOIN {$P}stock_moves sm
                ON sm.loc_code = l.loc_code
               AND sm.tran_date <= '{$today}'
               AND sm.stock_id IN (
                   SELECT stock_id FROM {$P}stock_master
                   WHERE category_id = 1 AND mb_flag NOT IN ('D','F')
               )
            GROUP BY l.loc_code, l.location_name
            HAVING current_qty > 0
            ORDER BY current_qty DESC
        ";

        try {
            $rows = $this->kirima()->select($sql);
            Cache::put($cacheKey, $rows, 300);
            return ApiResponse::success($rows, 'Milk by location retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Milk by location failed: ' . $e->getMessage());
        }
    }
}
