<?php

namespace App\Http\Controllers\MilkCollection;

use App\Http\Controllers\Controller;
use App\Support\ReadsFromKirima;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MilkCollectionController
 *
 * Mirrors milk-collection methods from kirima/Classes/Dashboard.php:
 *   getDailyMillkRecords()   → GET /api/milk-collection/chart
 *   fetMilkCountRecords()    → GET /api/milk-collection/count
 *
 * All queries target the `kirima` database with table prefix `0_`.
 */
class MilkCollectionController extends Controller
{
    use ReadsFromKirima;

    private const P = '0_';

    private function fmt(string $date): string
    {
        return date('Y-m-d', strtotime($date));
    }

    /**
     * Returns true if the date range includes today — data should NOT be cached.
     * Historical ranges are immutable and cached for 30 days.
     */
    private function isLive(string $to): bool
    {
        return $to >= date('Y-m-d');
    }

    private function historicalTtl(): int
    {
        return 86400 * 30; // 30 days
    }

    /**
     * GET /api/milk-collection/chart?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * Daily milk chart data — mirrors getDailyMillkRecords().
     *
     * Returns:
     * {
     *   labels:   string[]   // "Mar 12"
     *   expected: float[]    // litres from farmers (non-scale)
     *   actual:   float[]    // expected + tare
     *   tare:     float[]    // scale tare readings
     * }
     */
    public function chart(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $from = $this->fmt($request->from);
        $to   = $this->fmt($request->to);
        $from = Carbon::parse($from)->format('Y-m-d');
        $to   = Carbon::parse($to)->format('Y-m-d');
        $stores =  $request->stores ;
        $cacheKey = "milk_chart_{$from}_{$to}_" . ($stores ?? 'all');
        if (!$this->isLive($to) && ($cached = Cache::get($cacheKey))) {
            return ApiResponse::success($cached, 'Milk chart data retrieved');
        }
        try {
            
            // $scaleIds = $this->kirima()->table(self::P . 'suppliers')
            //     ->where('supp_name', 'LIKE', '%scale%')
            //     ->pluck('supplier_id')->toArray();
            // $scaleIn = implode(',', array_map('intval', $scaleIds ?: [0]));

            // $rows = $this->kirima()->select(
            //     "SELECT
            //         po.ord_date,
            //         ROUND(SUM(CASE WHEN po.supplier_id IN ($scaleIn)
            //                   THEN pod.quantity_ordered ELSE 0 END), 2)  AS tare,
            //         ROUND(SUM(CASE WHEN po.supplier_id NOT IN ($scaleIn)
            //                   THEN pod.quantity_ordered ELSE 0 END), 2)  AS expected
            //      FROM " . self::P . "purch_orders po USE INDEX (idx_date_order_supp)
            //      STRAIGHT_JOIN " . self::P . "purch_order_details pod USE INDEX (idx_order_item_qty) ON pod.order_no = po.order_no AND pod.item_code = '0001'
            //      WHERE po.ord_date BETWEEN ? AND ?
            //        AND po.ord_date > '2022-11-30'
            //      GROUP BY po.ord_date
            //      ORDER BY po.ord_date ASC",
            //     [$from, $to]
            // );

            $labels   = [];
            $expected = [];
            $actual   = [];
            $tare     = []; 
            // foreach ($rows as $row) {
                // $labels[]   = date('M j', strtotime($row->ord_date));
                // $exp        = (float) $row->expected;
                // $tar        = (float) $row->tare;
                // $expected[] = $exp;
                // $tare[]     = $tar;
                // $actual[]   = $exp + $tar;   // mirrors: actual = expected + tare
// 
            // }
            $resdata=$this->storerevenue($request);
            $stores=$resdata[0];
            $graphdata=$resdata[1];
            // $top3 = collect($stores)
            //     ->sortByDesc('revenue')
            //     ->take(3)
            //     ->values()
            //     ->toArray();
            $sorted = collect($stores)->sortByDesc('revenue')->values();
            $top3 = $sorted->take(1)
                ->merge($sorted->take(-1))
                ->toArray();
            $result = compact('labels', 'expected', 'actual', 'tare', 'stores', 'top3', 'graphdata');
            if (!$this->isLive($to)) { Cache::put($cacheKey, $result, $this->historicalTtl()); }
            return ApiResponse::success($result, 'Milk chart data retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Milk chart query failed: ' . $e->getMessage());
        }
    }
    function getMillkFarmerCollection(Request $request){
           $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $from = $this->fmt($request->from);
        $to   = $this->fmt($request->to);
        $stores   =  $request->stores ;

    }

    function getTransactionsPerItem(Request $request){
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);
        $from     = $this->fmt($request->from);
        $to       = $this->fmt($request->to);
        $location = $request->stores ?? '';

        $cacheKey = "store_items_{$from}_{$to}_" . ($location ?: 'all');
        if (!$this->isLive($to) && ($cached = Cache::get($cacheKey))) {
            return ApiResponse::success($cached, 'Milk record retrieved');
        }

        $P        = self::P;
        $locQuote = $location !== '' ? $this->kirima()->getPdo()->quote($location) : null;

        // ── Index strategy ────────────────────────────────────────────────────
        // With a store filter: idx_loc_tran_date (loc_code, tran_date) is the
        //   most selective — it scans only that store's rows for the period.
        // Without a store filter: idx_tran_date_type (tran_date, type) covers
        //   the date range and type together.
        $forceIdx  = $locQuote ? 'idx_loc_tran_date'  : 'idx_tran_date_type';
        $locFilter = $locQuote ? "AND move.loc_code = {$locQuote}" : '';

        // ── Purchase price ────────────────────────────────────────────────────
        // Correlated sub-select per item using idx_stock_tran_date (stock_id,
        // tran_date) — resolves in ~87 targeted index lookups for a single
        // store, far cheaper than a full ROW_NUMBER() scan over all purchase
        // moves for the period.
        $priceSub = "(
                        SELECT sm.price
                        FROM   {$P}stock_moves sm FORCE INDEX (idx_stock_tran_date)
                        WHERE  sm.stock_id    = item.stock_id
                          AND  sm.tran_date  >= '$from'
                          AND  sm.tran_date  <= '$to'
                          AND  (sm.type = 25 OR sm.type = 21)
                          AND  sm.price > 0
                        ORDER  BY sm.tran_date
                        LIMIT  1
                    )";

        $sql = "SELECT
                    item.category_id,
                    category.description                                                    AS cat_description,
                    item.stock_id,
                    item.description,
                    move.loc_code                                                           AS store,
                    SUM(-move.qty)                                                          AS qty,
                    ROUND(SUM(-move.qty * move.price) / NULLIF(SUM(-move.qty), 0), 2)      AS unit_price,
                    SUM(-move.qty * move.price)                                             AS amount,
                    SUM(-IF(move.standard_cost <> 0,
                            move.qty * move.standard_cost,
                            move.qty * COALESCE(NULLIF(item.material_cost + item.labour_cost + item.overhead_cost, 0),
                                                item.purchase_cost, 0)))                   AS cost,
                    SUM(-move.qty) * COALESCE($priceSub, 0)                                AS price
                FROM {$P}stock_moves    move  FORCE INDEX ({$forceIdx})
                JOIN {$P}debtor_trans   trans ON  trans.trans_no = move.trans_no
                                              AND trans.type     = move.type
                JOIN {$P}stock_master   item  ON  item.stock_id  = move.stock_id
                JOIN {$P}stock_category category ON category.category_id = item.category_id
                WHERE move.tran_date >= '$from'
                  AND move.tran_date <= '$to'
                  AND move.type IN (13, 11)
                  AND (item.mb_flag = 'B' OR item.mb_flag = 'M' OR item.mb_flag = 'D')
                  $locFilter
                GROUP BY item.stock_id, item.category_id, category.description,
                         item.description, move.loc_code
                ORDER BY item.category_id, item.stock_id";

        try {
            $data = $this->kirima()->select($sql);
            if (!$this->isLive($to)) { Cache::put($cacheKey, $data, $this->historicalTtl()); }
            return ApiResponse::success($data, 'Milk record retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Store items query failed: ' . $e->getMessage());
        }
    }
 function gradercollection(Request $request){
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);
        $from      = $this->fmt($request->from);
        $to        = $this->fmt($request->to);
        $location  = $request->stores ?? '';
        $item_code = '0001';

        $cacheKey = "grader_collection_{$from}_{$to}_" . ($location ?: 'all');
        if ($cached = Cache::get($cacheKey)) {
            return ApiResponse::success($cached, 'Milk record retrieved');
        }

        try {
            // Pre-fetch scale supplier IDs to avoid LIKE '%scale%' per joined row
            $scaleIds = $this->kirima()->table(self::P . 'suppliers')
                ->where('supp_name', 'LIKE', '%scale%')
                ->pluck('supplier_id')->toArray();
            $scaleIn = implode(',', array_map('intval', $scaleIds ?: [0]));

            $locationFilter = $location !== '' ? "AND po.into_stock_location = " . $this->kirima()->getPdo()->quote($location) : '';

            // When querying dates after 2022-11-30 (all modern data), the legacy OR
            // condition is always satisfied by the second branch alone — simplify it
            // so the optimizer can use the ord_date index without an OR branch.
            $legacyCond = $from > '2022-11-30'
                ? "AND po.ord_date > '2022-11-30'"
                : "AND ((po.ord_date <= '2022-11-30' AND po.into_stock_location = 'BN') OR po.ord_date > '2022-11-30')";

            $sql = "SELECT STRAIGHT_JOIN po.into_stock_location, l.location_name,
                     ROUND(SUM(CASE WHEN po.supplier_id NOT IN ({$scaleIn}) THEN pod.quantity_ordered ELSE 0 END), 2) AS quantity_ordered,
                     ROUND(SUM(CASE WHEN po.supplier_id IN ({$scaleIn}) THEN pod.quantity_ordered ELSE 0 END), 2) AS tare
                 FROM " . self::P . "purch_orders po
                 JOIN " . self::P . "purch_order_details pod ON pod.order_no = po.order_no AND pod.item_code = '{$item_code}'
                 JOIN " . self::P . "locations l  ON l.loc_code = po.into_stock_location
                 WHERE po.ord_date BETWEEN '{$from}' AND '{$to}'
                   {$legacyCond}
                   {$locationFilter}
                 GROUP BY po.into_stock_location, l.location_name
                 ORDER BY quantity_ordered DESC";

            $data = $this->kirima()->select($sql);
            $ttl = $this->isLive($to) ? 300 : $this->historicalTtl();
            Cache::put($cacheKey, $data, $ttl);

            return ApiResponse::success($data, 'Milk record retrieved');

        } catch (\Throwable $e) {
            return ApiResponse::serverError('Milk count query failed: ' . $e->getMessage());
        }
    }

    function graderchartdata(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);
        $from      = $this->fmt($request->from);
        $to        = $this->fmt($request->to);
        $location  = $request->stores ?? '';
        $item_code = '0001';

        $cacheKey = "grader_chart_v2_{$from}_{$to}_" . ($location ?: 'all');
        if ($cached = Cache::get($cacheKey)) {
            return ApiResponse::success($cached, 'Grader chart data retrieved');
        }

        try {
            $scaleIds = $this->kirima()->table(self::P . 'suppliers')
                ->where('supp_name', 'LIKE', '%scale%')
                ->pluck('supplier_id')->toArray();
            $scaleIn = implode(',', array_map('intval', $scaleIds ?: [0]));

            $locationFilter    = $location !== '' ? "AND po.into_stock_location = " . $this->kirima()->getPdo()->quote($location) : '';
            $locationFilterRaw = $location !== '' ? "AND into_stock_location = "    . $this->kirima()->getPdo()->quote($location) : '';

            $legacyCond = $from > '2022-11-30'
                ? "AND po.ord_date > '2022-11-30'"
                : "AND ((po.ord_date <= '2022-11-30' AND po.into_stock_location = 'BN') OR po.ord_date > '2022-11-30')";

            $sql = "SELECT STRAIGHT_JOIN po.ord_date, po.into_stock_location, l.location_name,
                         ROUND(SUM(CASE WHEN po.supplier_id NOT IN ({$scaleIn}) THEN pod.quantity_ordered ELSE 0 END), 2) AS quantity_ordered,
                         ROUND(SUM(CASE WHEN po.supplier_id IN ({$scaleIn}) THEN pod.quantity_ordered ELSE 0 END), 2) AS tare_weight,
                         COUNT(DISTINCT CASE WHEN po.supplier_id NOT IN ({$scaleIn}) THEN po.supplier_id END) AS farmer_count
                     FROM " . self::P . "purch_orders po FORCE INDEX (ord_date)
                     JOIN " . self::P . "purch_order_details pod ON pod.order_no = po.order_no AND pod.item_code = '{$item_code}'
                     JOIN " . self::P . "locations l  ON l.loc_code = po.into_stock_location
                     WHERE po.ord_date BETWEEN '{$from}' AND '{$to}'
                       {$legacyCond}
                       {$locationFilter}
                     GROUP BY po.ord_date, po.into_stock_location, l.location_name
                     ORDER BY po.ord_date ASC";

            $data = $this->kirima()->select($sql);

            // ── Absent count per day × location ──────────────────────────────
            // Farmers who delivered at location L on D-1 but not on D at L.
            // Always per-location so Most/Least Absent KPIs are meaningful.
            $dayBefore = date('Y-m-d', strtotime("$from -1 day"));

            $absentSql = "SELECT t_prev.next_date AS ord_date, t_prev.into_stock_location,
                                 COUNT(DISTINCT t_prev.supplier_id) AS absent_count
                          FROM (
                              SELECT DISTINCT supplier_id, into_stock_location,
                                     DATE_ADD(ord_date, INTERVAL 1 DAY) AS next_date
                              FROM " . self::P . "purch_orders
                              WHERE ord_date BETWEEN '{$dayBefore}' AND DATE_SUB('{$to}', INTERVAL 1 DAY)
                                AND supplier_id NOT IN ({$scaleIn})
                                AND ord_date > '2022-11-30'
                                {$locationFilterRaw}
                          ) t_prev
                          LEFT JOIN (
                              SELECT DISTINCT supplier_id, into_stock_location, ord_date
                              FROM " . self::P . "purch_orders
                              WHERE ord_date BETWEEN '{$from}' AND '{$to}'
                                AND supplier_id NOT IN ({$scaleIn})
                                AND ord_date > '2022-11-30'
                                {$locationFilterRaw}
                          ) t_curr
                            ON t_curr.supplier_id = t_prev.supplier_id
                           AND t_curr.ord_date = t_prev.next_date
                           AND t_curr.into_stock_location = t_prev.into_stock_location
                          WHERE t_curr.supplier_id IS NULL
                            AND t_prev.next_date BETWEEN '{$from}' AND '{$to}'
                          GROUP BY t_prev.next_date, t_prev.into_stock_location";

            $absentRows = $this->kirima()->select($absentSql);
            $absentMap  = [];
            foreach ($absentRows as $r) {
                $absentMap[$r->ord_date . '_' . $r->into_stock_location] = (int) $r->absent_count;
            }

            foreach ($data as $row) {
                $row->absent_count = $absentMap[$row->ord_date . '_' . $row->into_stock_location] ?? 0;
            }

            $ttl = $this->isLive($to) ? 300 : $this->historicalTtl();
            Cache::put($cacheKey, $data, $ttl);

            return ApiResponse::success($data, 'Grader chart data retrieved');

        } catch (\Throwable $e) {
            return ApiResponse::serverError('Grader chart query failed: ' . $e->getMessage());
        }
    }

    function farmercollectionWithAnomalies(Request $request)
    {
        ini_set('memory_limit', '512M');

        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $from      = $this->fmt($request->from);
        $to        = $this->fmt($request->to);
        $location  = $request->stores ?? '';
        $item_code = '0001';
        $P         = self::P;

        $hist30_from = date('Y-m-d', strtotime("$from -30 days"));
        $hist30_to   = date('Y-m-d', strtotime("$from -1 day"));
        $hist7_from  = date('Y-m-d', strtotime("$from -7 days"));

        $locationFilter = $location !== '' ? "AND into_stock_location = '{$location}'" : '';

        $cacheKey = "farmer_anomalies_{$from}_{$to}_" . ($location ?: 'all');
        if (!$this->isLive($to) && ($cached = Cache::get($cacheKey))) {
            return ApiResponse::success($cached, 'Farmer collection with anomaly flags retrieved');
        }

        try {
            // Pre-fetch scale IDs to avoid LIKE '%scale%' per joined row
            $scaleIds = $this->kirima()->table("{$P}suppliers")
                ->where('supp_name', 'LIKE', '%scale%')
                ->pluck('supplier_id')->toArray();
            $scaleIn = implode(',', array_map('intval', $scaleIds ?: [0]));

            // Simplify the legacy OR when all queried dates are after the cutoff
            $legacyCond = $from > '2022-11-30'
                ? "AND po.ord_date > '2022-11-30'"
                : "AND ((po.ord_date <= '2022-11-30' AND po.into_stock_location = 'BN') OR po.ord_date > '2022-11-30')";

            // ── Step 1: Main collection rows ──────────────────────────────────
            // Group by farmer+date (merging AM/PM shifts into one daily row).
            // Only select columns the frontend uses; force join order via STRAIGHT_JOIN
            // and point the optimizer to the date index.
            $rows = $this->kirima()->select("
                SELECT STRAIGHT_JOIN po.supplier_id,
                       s.member_no, s.supp_name, po.ord_date,
                       ROUND(SUM(pod.quantity_ordered), 2) AS quantity_ordered,
                       r.rname, l.location_name, po.into_stock_location
                FROM {$P}purch_orders po FORCE INDEX (ord_date)
                JOIN {$P}purch_order_details pod ON pod.order_no = po.order_no AND pod.item_code = '{$item_code}'
                JOIN {$P}suppliers s             ON s.supplier_id = po.supplier_id
                JOIN {$P}routes r                ON r.code = pod.route_id
                JOIN {$P}locations l             ON l.loc_code = po.into_stock_location
                WHERE po.ord_date BETWEEN '{$from}' AND '{$to}'
                  AND po.supplier_id NOT IN ({$scaleIn})
                  {$legacyCond}
                  {$locationFilter}
                GROUP BY po.supplier_id, s.member_no, s.supp_name, po.ord_date,
                         r.rname, l.location_name, po.into_stock_location
                ORDER BY po.ord_date ASC
            ");

            if (empty($rows)) {
                return ApiResponse::success([], 'Farmer collection with anomaly flags retrieved');
            }

            // ── Step 2: Collect unique supplier IDs from result ───────────────
            $supplierIds = array_unique(array_column($rows, 'supplier_id'));
            $idList      = implode(',', array_map('intval', $supplierIds));

            // ── Step 3: 30-day history — use STRAIGHT_JOIN + date index ───────
            $hist30Rows = $this->kirima()->select("
                SELECT STRAIGHT_JOIN po2.supplier_id,
                       ROUND(AVG(pod2.quantity_ordered),        4) AS hist_avg,
                       ROUND(STDDEV_POP(pod2.quantity_ordered), 4) AS hist_std
                FROM {$P}purch_orders po2 FORCE INDEX (ord_date)
                JOIN {$P}purch_order_details pod2 ON pod2.order_no = po2.order_no AND pod2.item_code = '{$item_code}'
                WHERE po2.ord_date BETWEEN '{$hist30_from}' AND '{$hist30_to}'
                  AND po2.supplier_id IN ({$idList})
                GROUP BY po2.supplier_id
            ");
            // Use plain arrays (not Collections) for O(1) key lookup without method-call overhead
            $hist30Map = [];
            foreach ($hist30Rows as $h) { $hist30Map[$h->supplier_id] = $h; }

            // ── Step 4: 7-day active days ─────────────────────────────────────
            $hist7Rows = $this->kirima()->select("
                SELECT STRAIGHT_JOIN po2.supplier_id,
                       COUNT(DISTINCT po2.ord_date) AS active_days_last7
                FROM {$P}purch_orders po2 FORCE INDEX (ord_date)
                JOIN {$P}purch_order_details pod2 ON pod2.order_no = po2.order_no AND pod2.item_code = '{$item_code}'
                WHERE pod2.quantity_ordered > 0
                  AND po2.ord_date BETWEEN '{$hist7_from}' AND '{$hist30_to}'
                  AND po2.supplier_id IN ({$idList})
                GROUP BY po2.supplier_id
            ");
            $hist7Map = [];
            foreach ($hist7Rows as $h) { $hist7Map[$h->supplier_id] = $h->active_days_last7; }

            // ── Step 5: Map history onto rows and detect anomalies ────────────
            // Inlined anomaly logic avoids a method call per row (saves ~37ms × 151k rows).
            foreach ($rows as $row) {
                $h30  = $hist30Map[$row->supplier_id] ?? null;
                $qty  = (float) $row->quantity_ordered;
                $avg  = (float) ($h30->hist_avg ?? 0);
                $std  = (float) ($h30->hist_std ?? 0);
                $days = (int)   ($hist7Map[$row->supplier_id] ?? 0);

                $severity = null;
                $reasons  = [];

                if ($qty == 0 && $days >= 3) {
                    $severity  = 'critical';
                    $reasons[] = "No collection; farmer supplied on {$days} of last 7 days";
                }
                if ($qty > 0 && $qty < 0.5) {
                    $severity  = 'critical';
                    $reasons[] = "Suspiciously low delivery: " . round($qty, 2) . " L (below 0.5 L)";
                }
                if ($avg > 0 && $std > 0) {
                    $z = ($qty - $avg) / $std;
                    if (abs($z) >= 2.0) {
                        $severity  = ($severity === 'critical') ? 'critical' : 'warning';
                        $dir       = $z > 0 ? 'spike' : 'drop';
                        $reasons[] = "Quantity {$dir}: Z-score " . round($z, 2)
                                   . " (30-day avg " . round($avg, 1) . " L, std " . round($std, 1) . " L)";
                    }
                }
                if ($avg > 0 && $qty < ($avg * 0.5) && $severity === null) {
                    $severity  = 'info';
                    $reasons[] = "Below 50% of personal 30-day avg (" . round($avg, 1) . " L)";
                }

                $row->anomaly_severity = $severity;
                $row->anomaly_reasons  = $reasons;
                unset($row->supplier_id);
            }

            if (!$this->isLive($to)) { Cache::put($cacheKey, $rows, $this->historicalTtl()); }

            return ApiResponse::success($rows, 'Farmer collection with anomaly flags retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Anomaly query failed: ' . $e->getMessage());
        }
    }

    private function detectAnomaly(object $row): array
    {
        $qty   = (float) $row->quantity_ordered;
        $avg   = (float) ($row->hist_avg ?? 0);
        $std   = (float) ($row->hist_std ?? 0);
        $days7 = (int)   ($row->active_days_last7 ?? 0);

        $severity = null;
        $reasons  = [];

        // Rule 1 — zero collection from an active farmer
        if ($qty == 0 && $days7 >= 3) {
            $severity  = 'critical';
            $reasons[] = "No collection; farmer supplied on {$days7} of last 7 days";
        }

        // Rule 2 — Z-score spike or drop (only when history exists)
        if ($avg > 0 && $std > 0) {
            $z = ($qty - $avg) / $std;
            if (abs($z) >= 2.0) {
                $severity  = ($severity === 'critical') ? 'critical' : 'warning';
                $direction = $z > 0 ? 'spike' : 'drop';
                $reasons[] = "Quantity {$direction}: Z-score " . round($z, 2)
                           . " (30-day avg " . round($avg, 1) . " L, std " . round($std, 1) . " L)";
            }
        }

        // Rule 3 — below 50 % of personal average (gradual decline)
        if ($avg > 0 && $qty < ($avg * 0.5) && $severity === null) {
            $severity  = 'info';
            $reasons[] = "Below 50% of personal 30-day avg (" . round($avg, 1) . " L)";
        }

        return ['severity' => $severity, 'reasons' => $reasons];
    }

    // ── Cached lookup maps (suppliers / routes / locations) ──────────────────
    // Loaded once per process (TTL 300s). Eliminates 3 JOIN lookups per row in
    // the heavy farmer queries, letting those queries touch only the two tables
    // that have covering indexes.
    private function getLookups(): array
    {
        return Cache::remember('fc_lookups', 300, function () {
            $P = self::P;
            $suppliers = $this->kirima()->select("SELECT supplier_id, member_no, supp_name FROM {$P}suppliers");
            $routes    = $this->kirima()->select("SELECT code, rname FROM {$P}routes");
            $locations = $this->kirima()->select("SELECT loc_code, location_name FROM {$P}locations");

            $suppMap  = [];
            $tare_ids = [];
            foreach ($suppliers as $s) {
                $suppMap[(int)$s->supplier_id] = $s;
                if ((int)$s->member_no === 0) {
                    $tare_ids[] = (int)$s->supplier_id;
                }
            }
            $routeMap = [];
            foreach ($routes as $r) { $routeMap[$r->code] = $r->rname; }
            $locMap = [];
            foreach ($locations as $l) { $locMap[$l->loc_code] = $l->location_name; }

            return compact('suppMap', 'tare_ids', 'routeMap', 'locMap');
        });
    }

    /**
     * Build AND SQL filters for farmer collection endpoints.
     * Supports: farmer, route, location (name), shift, legacy search (OR across fields).
     * Exact store code still applied separately via $request->stores.
     *
     * @return array{sql:string,key:string,empty:bool}
     */
    private function resolveFarmerFieldFilters(Request $request, array $suppMap, array $routeMap, array $locMap): array
    {
        $farmer = trim((string) ($request->farmer ?? ''));
        $route  = trim((string) ($request->route ?? ''));
        $locQ   = trim((string) ($request->location ?? ''));
        $shift  = trim((string) ($request->shift ?? ''));
        $search = trim((string) ($request->search ?? ''));

        $pdo = $this->kirima()->getPdo();
        $and = [];
        $keyParts = [
            'f=' . mb_strtolower($farmer),
            'r=' . mb_strtolower($route),
            'l=' . mb_strtolower($locQ),
            's=' . mb_strtoupper($shift),
            'q=' . mb_strtolower($search),
        ];

        if ($farmer !== '') {
            $sl = mb_strtolower($farmer);
            $ids = [];
            foreach ($suppMap as $sid => $s) {
                if (str_contains(mb_strtolower((string) ($s->supp_name ?? '')), $sl)
                    || str_contains((string) ($s->member_no ?? ''), $sl)) {
                    $ids[] = $sid;
                }
            }
            if (empty($ids)) {
                return ['sql' => '', 'key' => md5(implode('|', $keyParts)), 'empty' => true];
            }
            $and[] = 'po.supplier_id IN (' . implode(',', $ids) . ')';
        }

        if ($route !== '') {
            $sl = mb_strtolower($route);
            $codes = [];
            foreach ($routeMap as $code => $rname) {
                if (str_contains(mb_strtolower((string) $rname), $sl)
                    || str_contains(mb_strtolower((string) $code), $sl)) {
                    $codes[] = $pdo->quote($code);
                }
            }
            if (empty($codes)) {
                return ['sql' => '', 'key' => md5(implode('|', $keyParts)), 'empty' => true];
            }
            $and[] = 'pod.route_id IN (' . implode(',', $codes) . ')';
        }

        if ($locQ !== '') {
            $sl = mb_strtolower($locQ);
            $codes = [];
            foreach ($locMap as $lcode => $lname) {
                if (str_contains(mb_strtolower((string) $lname), $sl)
                    || str_contains(mb_strtolower((string) $lcode), $sl)) {
                    $codes[] = $pdo->quote($lcode);
                }
            }
            if (empty($codes)) {
                return ['sql' => '', 'key' => md5(implode('|', $keyParts)), 'empty' => true];
            }
            $and[] = 'po.into_stock_location IN (' . implode(',', $codes) . ')';
        }

        if ($shift !== '') {
            $and[] = 'UPPER(pod.shift) = ' . $pdo->quote(mb_strtoupper($shift));
        }

        // Legacy free-text search — OR across farmer / route / location names
        if ($search !== '') {
            $sl = mb_strtolower($search);
            $matchSuppliers = [];
            foreach ($suppMap as $sid => $s) {
                if (str_contains(mb_strtolower((string) ($s->supp_name ?? '')), $sl)
                    || str_contains((string) ($s->member_no ?? ''), $sl)) {
                    $matchSuppliers[] = $sid;
                }
            }
            $matchRoutes = [];
            foreach ($routeMap as $code => $rname) {
                if (str_contains(mb_strtolower((string) $rname), $sl)) {
                    $matchRoutes[] = $pdo->quote($code);
                }
            }
            $matchLocs = [];
            foreach ($locMap as $lcode => $lname) {
                if (str_contains(mb_strtolower((string) $lname), $sl)) {
                    $matchLocs[] = $pdo->quote($lcode);
                }
            }
            $clauses = [];
            if (!empty($matchSuppliers)) $clauses[] = 'po.supplier_id IN (' . implode(',', $matchSuppliers) . ')';
            if (!empty($matchRoutes))    $clauses[] = 'pod.route_id IN (' . implode(',', $matchRoutes) . ')';
            if (!empty($matchLocs))      $clauses[] = 'po.into_stock_location IN (' . implode(',', $matchLocs) . ')';

            if (empty($clauses)) {
                return ['sql' => '', 'key' => md5(implode('|', $keyParts)), 'empty' => true];
            }
            $and[] = '(' . implode(' OR ', $clauses) . ')';
        }

        $sql = '';
        foreach ($and as $clause) {
            $sql .= " AND {$clause}";
        }

        return ['sql' => $sql, 'key' => md5(implode('|', $keyParts)), 'empty' => false];
    }

    // ── KPI aggregates endpoint (fast — no row loading) ──────────────────────
    public function farmercollectionKpis(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);
        $from      = $this->fmt($request->from);
        $to        = $this->fmt($request->to);
        $location  = $request->stores ?? '';
        $item_code = '0001';
        $P         = self::P;

        // Load lookup maps — small tables, cached 5 min
        ['suppMap' => $suppMap, 'tare_ids' => $tare_ids, 'routeMap' => $routeMap, 'locMap' => $locMap]
            = $this->getLookups();

        $fieldFilters = $this->resolveFarmerFieldFilters($request, $suppMap, $routeMap, $locMap);

        // Full route list for dropdowns (independent of date filters / cache shape).
        $allRoutes = [];
        foreach ($routeMap as $code => $name) {
            $allRoutes[] = (object)['code' => (string) $code, 'name' => (string) $name];
        }
        usort($allRoutes, fn($a, $b) => strcmp($a->name, $b->name));

        $cacheKey = "farmer_kpis_v2_{$from}_{$to}_" . ($location ?: 'all') . '_' . $fieldFilters['key'];
        if (!$this->isLive($to) && ($cached = Cache::get($cacheKey))) {
            if (empty($cached['routes'])) {
                $cached['routes'] = $allRoutes;
            }
            return ApiResponse::success($cached, 'KPIs retrieved');
        }

        if ($fieldFilters['empty']) {
            return ApiResponse::success([
                'agg'          => null,
                'top_farmer'   => null,
                'least_farmer' => null,
                'top_route'    => null,
                'centers'      => [],
                'routes'       => $allRoutes,
            ], 'KPIs retrieved');
        }

        $tare_in = implode(',', $tare_ids ?: [0]);

        $locationFilter = $location !== '' ? "AND po.into_stock_location = '{$location}'" : '';
        $legacyCond = $from > '2022-11-30'
            ? "AND po.ord_date > '2022-11-30'"
            : "AND ((po.ord_date <= '2022-11-30' AND po.into_stock_location = 'BN') OR po.ord_date > '2022-11-30')";
        $searchFilter = $fieldFilters['sql'];

        // Two-table base — only the two tables with covering indexes, no JOINs.
        $baseFrom = "
            FROM {$P}purch_orders po FORCE INDEX (idx_cover_po_date)
            STRAIGHT_JOIN {$P}purch_order_details pod
                       ON pod.order_no = po.order_no AND pod.item_code = '{$item_code}'
            WHERE po.ord_date BETWEEN '{$from}' AND '{$to}'
              {$legacyCond}
              {$locationFilter}
              {$searchFilter}
        ";

        try {
            // ── Query 1: totals + active centers in one pass ──────────────────
            // GROUP_CONCAT collects distinct location codes at zero extra scan cost.
            $agg = $this->kirima()->selectOne("
                SELECT
                    COUNT(*) AS record_count,
                    ROUND(SUM(pod.quantity_ordered), 2) AS total_qty,
                    ROUND(SUM(CASE WHEN po.supplier_id IN ({$tare_in}) THEN pod.quantity_ordered ELSE 0 END), 2) AS tare_qty,
                    ROUND(SUM(CASE WHEN po.supplier_id NOT IN ({$tare_in}) THEN pod.quantity_ordered * pod.unit_price ELSE 0 END), 2) AS total_value,
                    ROUND(SUM(CASE WHEN UPPER(pod.shift) = 'MORNING' THEN pod.quantity_ordered ELSE 0 END), 2) AS morning_qty,
                    ROUND(SUM(CASE WHEN UPPER(pod.shift) = 'EVENING' THEN pod.quantity_ordered ELSE 0 END), 2) AS evening_qty,
                    COUNT(DISTINCT CASE WHEN po.supplier_id NOT IN ({$tare_in}) THEN po.supplier_id END) AS unique_farmers,
                    COUNT(DISTINCT po.ord_date) AS day_count,
                    GROUP_CONCAT(DISTINCT po.into_stock_location ORDER BY po.into_stock_location) AS center_codes,
                    GROUP_CONCAT(DISTINCT pod.route_id ORDER BY pod.route_id) AS route_codes
                {$baseFrom}
            ");

            // Extract centers from the aggregated string — no extra query needed.
            $centerCodes = $agg->center_codes ? explode(',', $agg->center_codes) : [];
            $centers = array_map(fn($c) => (object)['code' => $c, 'name' => $locMap[$c] ?? $c], $centerCodes);
            usort($centers, fn($a, $b) => strcmp($a->name, $b->name));
            unset($agg->center_codes);

            unset($agg->route_codes);

            // ── Query 2: per-supplier+route totals (only real farmers) ────────
            // GROUP BY supplier_id + route_id — no name JOINs; enrich in PHP.
            $farmerRows = $this->kirima()->select("
                SELECT po.supplier_id, pod.route_id,
                       ROUND(SUM(pod.quantity_ordered), 2) AS total_qty
                {$baseFrom}
                  AND po.supplier_id NOT IN ({$tare_in})
                GROUP BY po.supplier_id, pod.route_id
                ORDER BY NULL
            ");

            $byFarmer = [];
            $byRoute  = [];
            foreach ($farmerRows as $row) {
                $sid  = (int) $row->supplier_id;
                $rname = $routeMap[$row->route_id] ?? $row->route_id;
                $qty  = (float) $row->total_qty;

                if (!isset($byFarmer[$sid])) {
                    $sup = $suppMap[$sid] ?? null;
                    $byFarmer[$sid] = (object)[
                        'member_no' => $sup->member_no ?? $sid,
                        'supp_name' => $sup->supp_name ?? '',
                        'total_qty' => 0.0,
                    ];
                }
                $byFarmer[$sid]->total_qty += $qty;
                $byRoute[$rname] = ($byRoute[$rname] ?? 0.0) + $qty;
            }

            $topFarmer   = null;
            $leastFarmer = null;
            foreach ($byFarmer as $f) {
                if ($topFarmer   === null || $f->total_qty > $topFarmer->total_qty)   $topFarmer   = $f;
                if ($leastFarmer === null || $f->total_qty < $leastFarmer->total_qty) $leastFarmer = $f;
            }
            if (count($byFarmer) <= 1) $leastFarmer = null;

            $topRoute = null;
            if (!empty($byRoute)) {
                arsort($byRoute);
                $rn = array_key_first($byRoute);
                $topRoute = (object)['rname' => $rn, 'total_qty' => round($byRoute[$rn], 2)];
            }

            $data = [
                'agg'          => $agg,
                'top_farmer'   => $topFarmer,
                'least_farmer' => $leastFarmer,
                'top_route'    => $topRoute,
                'centers'      => $centers,
                'routes'       => $allRoutes,
            ];

            // Today = always fresh (no cache). Historical: 30 days (immutable).
            if (!$this->isLive($to)) {
                $ttl = $fieldFilters['sql'] !== '' ? 600 : $this->historicalTtl();
                Cache::put($cacheKey, $data, $ttl);
            }

            return ApiResponse::success($data, 'KPIs retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('KPI query failed: ' . $e->getMessage());
        }
    }

    // ── Paginated farmer collection rows ─────────────────────────────────────
    function farmercollection(Request $request){
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);
        $from     = $this->fmt($request->from);
        $to       = $this->fmt($request->to);
        $location = $request->stores ?? '';
        $page     = max(1, (int) ($request->page ?? 1));
        $perPage  = min(5000, max(10, (int) ($request->per_page ?? 10)));
        $item_code = '0001';
        $P         = self::P;

        // Load lookup maps — suppliers/routes/locations (cached 5 min)
        ['suppMap' => $suppMap, 'tare_ids' => $tare_ids, 'routeMap' => $routeMap, 'locMap' => $locMap]
            = $this->getLookups();

        $fieldFilters = $this->resolveFarmerFieldFilters($request, $suppMap, $routeMap, $locMap);
        if ($fieldFilters['empty']) {
            return ApiResponse::success([
                'data' => [], 'total' => 0, 'per_page' => $perPage,
                'current_page' => $page, 'last_page' => 1,
            ], 'Milk record retrieved');
        }

        $tare_in = implode(',', $tare_ids ?: [0]);

        $locationFilter = $location !== '' ? "AND po.into_stock_location = '{$location}'" : '';
        $legacyCond = $from > '2022-11-30'
            ? "AND po.ord_date > '2022-11-30'"
            : "AND ((po.ord_date <= '2022-11-30' AND po.into_stock_location = 'BN') OR po.ord_date > '2022-11-30')";
        $searchFilter = $fieldFilters['sql'];

        $offset = ($page - 1) * $perPage;

        // Two-table base — covering indexes only, no lookup JOINs.
        $baseFrom = "
            FROM {$P}purch_orders po FORCE INDEX (idx_cover_po_date)
            STRAIGHT_JOIN {$P}purch_order_details pod
                       ON pod.order_no = po.order_no AND pod.item_code = '{$item_code}'
            WHERE po.ord_date BETWEEN '{$from}' AND '{$to}'
              AND po.supplier_id NOT IN ({$tare_in})
              {$legacyCond}
              {$locationFilter}
              {$searchFilter}
        ";

        // Cache the COUNT separately — doesn't change per page flip.
        $countKey = "farmer_cnt_{$from}_{$to}_" . ($location ?: 'all') . '_' . $fieldFilters['key'];
        $total    = $this->isLive($to) ? null : Cache::get($countKey);

        try {
            if ($total === null) {
                $countRow = $this->kirima()->selectOne("
                    SELECT COUNT(*) AS total FROM (
                        SELECT 1
                        {$baseFrom}
                        GROUP BY po.supplier_id, po.ord_date, pod.shift, pod.unit_price,
                                 pod.route_id, po.into_stock_location
                    ) AS cnt
                ");
                $total = (int) ($countRow->total ?? 0);
                if (!$this->isLive($to)) {
                    Cache::put($countKey, $total, $this->historicalTtl());
                }
            }

            $rows = $this->kirima()->select("
                SELECT po.supplier_id, po.ord_date, pod.shift,
                       ROUND(SUM(pod.quantity_ordered), 2) AS quantity_ordered,
                       pod.unit_price, pod.route_id, po.into_stock_location
                {$baseFrom}
                GROUP BY po.supplier_id, po.ord_date, pod.shift, pod.unit_price,
                         pod.route_id, po.into_stock_location
                ORDER BY po.ord_date ASC
                LIMIT {$perPage} OFFSET {$offset}
            ");

            // Enrich with names from lookup maps (no extra DB round trips)
            foreach ($rows as $row) {
                $sid  = (int) $row->supplier_id;
                $sup  = $suppMap[$sid] ?? null;
                $row->member_no      = $sup->member_no ?? $sid;
                $row->supp_name      = $sup->supp_name ?? '';
                $row->rname          = $routeMap[$row->route_id] ?? $row->route_id;
                $row->location_name  = $locMap[$row->into_stock_location] ?? $row->into_stock_location;
                unset($row->supplier_id, $row->route_id);
            }

            return ApiResponse::success([
                'data'         => $rows,
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
            ], 'Milk record retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Milk count query failed: ' . $e->getMessage());
        }
    }

    // ── Export: all rows, no pagination, no COUNT — one fast query ────────────
    public function farmercollectionExport(Request $request)
    {
        ini_set('memory_limit', '512M');

        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $from      = $this->fmt($request->from);
        $to        = $this->fmt($request->to);
        $location  = $request->stores ?? '';
        $item_code = '0001';
        $P         = self::P;

        ['suppMap' => $suppMap, 'tare_ids' => $tare_ids, 'routeMap' => $routeMap, 'locMap' => $locMap]
            = $this->getLookups();

        $fieldFilters = $this->resolveFarmerFieldFilters($request, $suppMap, $routeMap, $locMap);
        if ($fieldFilters['empty']) {
            return ApiResponse::success([], 'No matching records');
        }

        $tare_in = implode(',', $tare_ids ?: [0]);

        $locationFilter = $location !== '' ? "AND po.into_stock_location = '{$location}'" : '';
        $legacyCond = $from > '2022-11-30'
            ? "AND po.ord_date > '2022-11-30'"
            : "AND ((po.ord_date <= '2022-11-30' AND po.into_stock_location = 'BN') OR po.ord_date > '2022-11-30')";
        $searchFilter = $fieldFilters['sql'];

        try {
            $rows = $this->kirima()->select("
                SELECT po.supplier_id, po.ord_date, pod.shift,
                       ROUND(SUM(pod.quantity_ordered), 2) AS quantity_ordered,
                       pod.unit_price, pod.route_id, po.into_stock_location
                FROM {$P}purch_orders po FORCE INDEX (idx_cover_po_date)
                STRAIGHT_JOIN {$P}purch_order_details pod
                           ON pod.order_no = po.order_no AND pod.item_code = '{$item_code}'
                WHERE po.ord_date BETWEEN '{$from}' AND '{$to}'
                  AND po.supplier_id NOT IN ({$tare_in})
                  {$legacyCond}
                  {$locationFilter}
                  {$searchFilter}
                GROUP BY po.supplier_id, po.ord_date, pod.shift, pod.unit_price,
                         pod.route_id, po.into_stock_location
                ORDER BY po.ord_date ASC
            ");

            foreach ($rows as $row) {
                $sid = (int) $row->supplier_id;
                $sup = $suppMap[$sid] ?? null;
                $row->member_no     = $sup->member_no ?? $sid;
                $row->supp_name     = $sup->supp_name ?? '';
                $row->rname         = $routeMap[$row->route_id] ?? $row->route_id;
                $row->location_name = $locMap[$row->into_stock_location] ?? $row->into_stock_location;
                unset($row->supplier_id, $row->route_id);
            }

            return ApiResponse::success($rows, 'Export data retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Export query failed: ' . $e->getMessage());
        }
    }

    public function getTransactions($from, $to, $summary, $datesummary, $location = '')
    {
        // ── SELECT ────────────────────────────────────────────────────────────
        $select = "SELECT
            item.category_id,
            category.description AS cat_description,
            item.stock_id,
            item.description,
            item.inactive,
            item.mb_flag,
            move.loc_code";

        if ($summary) {
            $select .= ",\n            l.location_name";
        }

        if (!$summary) {
            $select .= ",\n            trans.debtor_no,\n            debtor.name AS debtor_name";
        }

        if (!$datesummary) {
            $select .= ",\n            move.tran_date";
        }

        $P = self::P;

        $select .= ",
            SUM(-move.qty)                                                          AS qty,
            SUM(-move.qty * move.price)                                             AS amt,
            SUM(-IF(move.standard_cost <> 0,
                    move.qty * move.standard_cost,
                    move.qty * item.material_cost))                                 AS cost,
            SUM(-move.qty) * MAX(COALESCE(pp.price, 0))                            AS price";

        // ── FROM ──────────────────────────────────────────────────────────────
        // Drive from stock_moves using idx_tran_date_type (tran_date, type).
        // The composite index covers both the date range AND the type filter in
        // one scan, narrowing rows before any JOINs touch larger tables.
        //
        // pp: pre-computes one purchase price per stock_id (type 25/21) via a
        // single derived-table pass with ROW_NUMBER() — avoids a correlated
        // sub-select that would re-scan stock_moves for every outer row.
        $joins = "FROM {$P}stock_moves    move  FORCE INDEX (idx_tran_date_type)
            JOIN {$P}debtor_trans  trans ON  trans.trans_no = move.trans_no
                                         AND trans.type     = move.type
            JOIN {$P}stock_master  item  ON  item.stock_id  = move.stock_id
            JOIN {$P}stock_category category ON category.category_id = item.category_id
            LEFT JOIN (
                SELECT stock_id, price
                FROM (
                    SELECT stock_id, price,
                           ROW_NUMBER() OVER (PARTITION BY stock_id ORDER BY tran_date) AS rn
                    FROM {$P}stock_moves FORCE INDEX (idx_tran_date_type)
                    WHERE tran_date >= '$from'
                      AND tran_date <= '$to'
                      AND (type = 25 OR type = 21)
                ) ranked
                WHERE rn = 1
            ) pp ON pp.stock_id = item.stock_id";

        if ($summary) {
            $joins .= "\n            JOIN {$P}locations      l      ON  l.loc_code      = move.loc_code";
        } else {
            $joins .= "\n            JOIN {$P}debtors_master debtor ON  debtor.debtor_no = trans.debtor_no";
        }

        // move.type = trans.type is guaranteed by the JOIN condition, so
        // IN(13,11) is equivalent to (trans.type=13 OR move.type=11) and
        // lets the optimizer use the idx_tran_date_type index on the leading column.
        $where = "WHERE move.tran_date >= '$from'
              AND move.tran_date <= '$to'
              AND move.type IN (13, 11)
              AND (item.mb_flag = 'B' OR item.mb_flag = 'M' OR item.mb_flag = 'D')";

        if ($location) {
            $where .= "\n              AND move.loc_code = '$location'";
        }

        // ── GROUP BY ──────────────────────────────────────────────────────────
        $group = "GROUP BY item.stock_id, item.category_id, category.description,
            item.description, item.inactive, item.mb_flag, move.loc_code";

        if ($summary) {
            $group .= ", l.location_name";
        } else {
            $group .= ", trans.debtor_no, debtor.debtor_no, debtor.name";
        }

        if (!$datesummary) {
            $group .= ", move.tran_date";
        }

        // ── ORDER BY ──────────────────────────────────────────────────────────
        $order = "ORDER BY item.category_id, item.stock_id";
        if (!$summary)     $order .= ", debtor.name";
        if (!$datesummary) $order .= ", move.tran_date";

        return $this->kirima()->select("$select\n$joins\n$where\n$group\n$order");
    }
   function storerevenue(Request $request): array
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);
        $from   = $this->fmt($request->from);
        $to     = $this->fmt($request->to);
        $stores = $request->stores;

        $cacheKey = "store_revenue_{$from}_{$to}_" . ($stores ?? 'all');
        if (!$this->isLive($to) && ($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $loc = $stores ?? '';

        // ── Query 1: store totals (summary=1, datesummary=1) ──────────────────
        // Returns one row per store — no date breakdown needed here.
        $storeRows = collect($this->getTransactions($from, $to, 1, 1, $loc));

        $revenues = $storeRows
            ->groupBy('loc_code')
            ->map(function ($items) {
                $revenue = (float) round($items->sum('amt'), 2);
                $cost    = $items->sum('price')<=0||$items->sum('price')==''?(float) round($items->sum('cost'), 2):(float) round($items->sum('price'), 2);
                $profit  = $revenue - $cost;
                $prices=(float) round($items->sum('price'),2);
                 $costs=(float) round($items->sum('cost'),2);
                return [
                    'location' => $items->first()->location_name,
                    'loc_code' => $items->first()->loc_code,
                    'revenue'  => $revenue,
                    'cost'     => $cost,
                    'costs'     => $costs,
                    'prices'   => $prices,
                    'profit'   => $profit,
                    'margin'   => $revenue ? round(($profit / $revenue) * 100, 2) : 0,
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->toArray();

        // ── Query 2: daily chart (lean — only tran_date + totals) ─────────────
        // Separate simple query: no item/category/debtor columns, just date + sums.
        $locFilter = $loc ? "AND move.loc_code = '$loc'" : '';
        $chartRows = $this->kirima()->select(
            "SELECT move.tran_date,
                    SUM(-move.qty * move.price)                                             AS amt,
                    SUM(-IF(move.standard_cost <> 0,
                            move.qty * move.standard_cost,
                            move.qty * item.material_cost))                                 AS cost
             FROM " . self::P . "stock_moves move FORCE INDEX (idx_tran_date_type)
             STRAIGHT_JOIN " . self::P . "stock_master item ON item.stock_id = move.stock_id
             WHERE move.tran_date BETWEEN ? AND ?
               AND move.type IN (13, 11)
               AND (item.mb_flag = 'B' OR item.mb_flag = 'M' OR item.mb_flag = 'D')
               $locFilter
             GROUP BY move.tran_date
             ORDER BY move.tran_date",
            [$from, $to]
        );

        $grouped = collect($chartRows)
            ->map(function ($row) {
                $revenue = (float) round($row->amt,  2);
                $cost    = (float) round($row->cost, 2);
                $profit  = $revenue - $cost;
                return [
                    'tran_date' => Carbon::parse($row->tran_date)->format('M j'),
                    'revenue'   => $revenue,
                    'cost'      => $cost,
                    'profit'    => $profit,
                    'margin'    => $revenue ? round(($profit / $revenue) * 100, 2) : 0,
                ];
            })
            ->values();

        $result = [$revenues, $grouped];
        if (!$this->isLive($to)) { Cache::put($cacheKey, $result, $this->historicalTtl()); }

        return $result;
    }
    /**
     * GET /api/milk-collection/count?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * Count of milk purchase orders — mirrors fetMilkCountRecords().
     */
    public function count(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($this->fmt($request->from))->format('Y-m-d');
        $to   = Carbon::parse($this->fmt($request->to))->format('Y-m-d');

        $cacheKey = "milk_count_{$from}_{$to}";
        if (!$this->isLive($to) && ($cached = Cache::get($cacheKey))) {
            return ApiResponse::success($cached, 'Milk record count retrieved');
        }

        try {
            // Use cached lookup maps to eliminate the suppliers JOIN
            ['tare_ids' => $tare_ids] = $this->getLookups();
            $tare_in = implode(',', $tare_ids ?: [0]);

            [$row, $datares, $activefarmer] = [
                $this->kirima()->selectOne(
                    "SELECT COUNT(*) AS counts FROM " . self::P . "purch_orders WHERE ord_date BETWEEN ? AND ?",
                    [$from, $to]
                ),
                $this->kirima()->select(
                    "SELECT
                        ROUND(SUM(CASE WHEN po.supplier_id IN ($tare_in) THEN pod.quantity_ordered ELSE 0 END), 2) AS tare,
                        ROUND(SUM(CASE WHEN po.supplier_id NOT IN ($tare_in) THEN pod.quantity_ordered ELSE 0 END), 2) AS expected
                     FROM " . self::P . "purch_orders po FORCE INDEX (idx_cover_po_date)
                     STRAIGHT_JOIN " . self::P . "purch_order_details pod ON pod.order_no = po.order_no AND pod.item_code = '0001'
                     WHERE po.ord_date BETWEEN ? AND ?
                       AND po.source_from = 'F'",
                    [$from, $to]
                ),
                $this->getActiveFarmers(),
            ];

            $result = [(int) ($row->counts ?? 0), $datares, $activefarmer];
            if (!$this->isLive($to)) { Cache::put($cacheKey, $result, $this->historicalTtl()); }

            return ApiResponse::success($result, 'Milk record count retrieved');

        } catch (\Throwable $e) {
            return ApiResponse::serverError('Milk count query failed: ' . $e->getMessage());
        }
    }
    
public function getActiveFarmers()
{
    $from = Carbon::now()->startOfMonth()->toDateString();
    $to   = Carbon::now()->endOfMonth()->toDateString();

    return Cache::remember("active_farmers_{$from}_{$to}", 120, function () use ($from, $to) {
        ['tare_ids' => $tare_ids] = $this->getLookups();
        $tare_in = implode(',', $tare_ids ?: [0]);

        $result = $this->kirima()->selectOne(
            "SELECT COUNT(DISTINCT po.supplier_id) AS active_farmers
             FROM " . self::P . "purch_orders po FORCE INDEX (idx_cover_po_date)
             STRAIGHT_JOIN " . self::P . "purch_order_details pod
                ON pod.order_no = po.order_no AND pod.item_code = '0001'
             WHERE po.ord_date BETWEEN ? AND ?
               AND po.source_from = 'F'
               AND po.supplier_id NOT IN ($tare_in)",
            [$from, $to]
        );

        return $result->active_farmers ?? 0;
    });
} 
public function getTareAndFarmerValue(Request $request){
 $from =  $request->input('from', date('Y-m-d'));
 $to =  $request->input('to', date('Y-m-d'));
 $from = Carbon::parse($from);
 $to = Carbon::parse($to);
 
}
    /**
     * GET /api/milk-collection/records?from=&to=&supp=&route=&shift=&location=
     *
     * Detailed purchase order records — mirrors get_transactions().
     */
    public function records(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $from     = $this->fmt($request->from);
        $to       = $this->fmt($request->to);
        $from = Carbon::parse($from)->format('Y-m-d');
         $to   = Carbon::parse($to)->format('Y-m-d');

        $supp     = $request->input('supp', '');
        $route    = $request->input('route', '');
        $shift    = $request->input('shift', '');
        $location = $request->input('location', '');

        try {
            $sql = "SELECT
                        po.order_no,
                        po.ord_date,
                        s.supp_name,
                        s.supp_type,
                        pod.quantity_ordered,
                        pod.route_id,
                        pod.shift,
                        po.into_stock_location,
                        ROUND(SUM(CASE WHEN s.supp_name LIKE '%scale%'
                                  THEN pod.quantity_ordered ELSE 0 END), 2) AS tare,
                        ROUND(SUM(CASE WHEN s.supp_name NOT LIKE '%scale%'
                                       AND s.supp_type = 'farmer'
                                  THEN pod.quantity_ordered ELSE 0 END), 2) AS expected,r.rname
                    FROM " . self::P . "purch_orders po
                    JOIN " . self::P . "purch_order_details pod ON pod.order_no = po.order_no
                    JOIN " . self::P . "suppliers s             ON s.supplier_id = po.supplier_id
                    LEFT JOIN " . self::P . "routes r           ON r.code = pod.route_id
                    WHERE pod.item_code = '0001'
                      AND po.ord_date BETWEEN ? AND ?
                      AND (
                            (po.ord_date <= '2022-11-30' AND po.into_stock_location = 'BN')
                            OR po.ord_date > '2022-11-30'
                          )";

            $params = [$from, $to];

            if ($supp) {
                $sql     .= " AND s.supp_name LIKE ?";
                $params[] = "%{$supp}%";
            }
            if ($route) {
                $sql     .= " AND pod.route_id = ?";
                $params[] = $route;
            }
            if ($shift) {
                $sql     .= " AND pod.shift = ?";
                $params[] = $shift;
            }
            if ($location) {
                $sql     .= " AND po.into_stock_location = ?";
                $params[] = $location;
            }

            $sql .= " GROUP BY po.order_no, pod.route_id, pod.shift
                      ORDER BY po.ord_date DESC, po.order_no DESC";

            $rows = $this->kirima()->select($sql, $params);

            return ApiResponse::success(
                array_map(fn($r) => (array) $r, $rows),
                'Milk records retrieved'
            );

        } catch (\Throwable $e) {
            return ApiResponse::serverError('Milk records query failed: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/milk-collection/absent-farmers
     *
     * Returns farmers who delivered to the given grader location in the
     * 90-day look-back window before `from` but have zero deliveries in
     * the requested `from`→`to` period.
     */
    public function absentFarmers(Request $request): JsonResponse
    {
        $request->validate([
            'from'   => 'required|date',
            'to'     => 'required|date|after_or_equal:from',
            'stores' => 'required|string',
        ]);

        $from      = $this->fmt($request->from);
        $to        = $this->fmt($request->to);
        $location  = $request->stores;
        $item_code = '0001';
        $P         = self::P;

        $yesterday = date('Y-m-d', strtotime("$from -1 day"));

        // If no data exists for yesterday (e.g. DB not yet updated for today),
        // fall back to the most recent date that has deliveries at this location.
        $hasYesterday = $this->kirima()->table(self::P . 'purch_orders')
            ->where('ord_date', $yesterday)
            ->where('into_stock_location', $location)
            ->exists();

        if (!$hasYesterday) {
            $latest = $this->kirima()->table(self::P . 'purch_orders')
                ->where('ord_date', '<', $from)
                ->where('into_stock_location', $location)
                ->max('ord_date');
            if (!$latest) {
                return ApiResponse::success([], 'No prior delivery data found for this grader');
            }
            $yesterday = $latest;
        }

        $cacheKey = "absent_farmers_{$from}_{$to}_{$location}";
        if (!$this->isLive($to) && ($cached = Cache::get($cacheKey))) {
            return ApiResponse::success($cached, 'Absent farmers retrieved');
        }

        try {
            $scaleIds = $this->kirima()->table("{$P}suppliers")
                ->where('supp_name', 'LIKE', '%scale%')
                ->pluck('supplier_id')->toArray();
            $scaleIn = implode(',', array_map('intval', $scaleIds ?: [0]));

            // Farmers who delivered yesterday
            $lookbackFarmers = $this->kirima()->select("
                SELECT DISTINCT po.supplier_id,
                       s.member_no, s.supp_name, s.contact AS phone,
                       r.rname,
                       l.location_name,
                       '{$yesterday}' AS last_delivery
                FROM {$P}purch_orders po
                JOIN {$P}purch_order_details pod ON pod.order_no = po.order_no AND pod.item_code = '{$item_code}'
                JOIN {$P}suppliers s ON s.supplier_id = po.supplier_id
                LEFT JOIN {$P}routes r ON r.code = po.route_id
                LEFT JOIN {$P}locations l ON l.loc_code = po.into_stock_location
                WHERE po.ord_date = '{$yesterday}'
                  AND po.into_stock_location = '{$location}'
                  AND po.supplier_id NOT IN ({$scaleIn})
            ");

            if (empty($lookbackFarmers)) {
                return ApiResponse::success([], 'No absent farmers found');
            }

            // Farmers who DID deliver in the selected period
            $activePeriod = $this->kirima()->select("
                SELECT DISTINCT po.supplier_id
                FROM {$P}purch_orders po
                JOIN {$P}purch_order_details pod ON pod.order_no = po.order_no AND pod.item_code = '{$item_code}'
                WHERE po.ord_date BETWEEN '{$from}' AND '{$to}'
                  AND po.into_stock_location = '{$location}'
                  AND po.supplier_id NOT IN ({$scaleIn})
                  AND po.ord_date > '2022-11-30'
            ");

            $activeIds = array_flip(array_column($activePeriod, 'supplier_id'));

            $absent = array_values(array_filter($lookbackFarmers, fn($f) => !isset($activeIds[$f->supplier_id])));

            usort($absent, fn($a, $b) => strcmp($a->supp_name, $b->supp_name));

            if (!$this->isLive($to)) { Cache::put($cacheKey, $absent, $this->historicalTtl()); }

            return ApiResponse::success($absent, 'Absent farmers retrieved');

        } catch (\Throwable $e) {
            return ApiResponse::serverError('Absent farmers query failed: ' . $e->getMessage());
        }
    }
}
