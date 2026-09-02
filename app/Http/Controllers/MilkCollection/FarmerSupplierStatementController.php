<?php

namespace App\Http\Controllers\MilkCollection;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * FarmerSupplierStatementController
 *
 * Per-store (or system-wide, when no store is given) milk stock
 * reconciliation ("Farmer Supplier Link" statement): everything that came
 * INTO a store — purchases from farmers plus transfers in from
 * graders/transporters, collection centers and cooling centers — against
 * everything that LEFT it — sales to customers plus transfers out to other
 * stores — for a date range.
 *
 * Data sources (all against the legacy `0_`-prefixed kirimaa schema):
 *   1. Purchases from farmers → 0_purch_orders + 0_purch_order_details,
 *      filtered to into_stock_location = the store, excluding tare/scale
 *      pseudo-suppliers (member_no = '0'). Mirrors
 *      MilkCollectionController::farmercollectionKpis().
 *   2-4. Transfers in → 0_stock_moves type=16 (ST_LOCTRANSFER), qty > 0,
 *      loc_code = the store. Each transfer writes a matching pair of rows
 *      sharing trans_no: the IN row carries loc_code_from = the source
 *      location, the OUT row (qty < 0) does not. Source locations are
 *      bucketed by 0_locations.location_type.
 *   5. Sales → 0_stock_moves type IN (13, 11), qty < 0 (13 = customer
 *      delivery, 11 = customer credit note), joined through
 *      0_debtor_trans (trans_no + type) to 0_debtors_master for the
 *      customer name. Mirrors MilkCollectionController::getTransactions().
 *   6. Transfer out → the mirror of (2-4): type=16, qty < 0, loc_code = the
 *      store, self-joined on trans_no + stock_id to the paired IN row to
 *      recover the destination location.
 *
 * Headline `stats` block (total collections/deliveries/sales/manufacturing/
 * consumables) reuses the figures above plus two extra lookups:
 *   - manufacturing_qty → 0_stock_moves type=29 (ST_MANURECEIVE), qty < 0,
 *      stock_id '0001' at the store — this business books raw-milk
 *      consumption for UHT/yoghurt production on this move type.
 *   - consumables → transfers out (same mechanism as #6) narrowed to the
 *      internal-use location codes: KT001 (Kitchen), RJ001 (Reject Store),
 *      S004 + SP001 (Spillage/Damage — two codes exist for it).
 *
 * Location categorisation (0_locations.location_type) — confirmed by the
 * business, NOT auto-derived from naming convention:
 *   1 = Grader / Transporter
 *   2 = Cooling Center
 *   3 = Main Factory
 *   4 = Collection Center
 *   0 / unset = Unclassified — most locations are not tagged yet, so this
 *      bucket is surfaced rather than hidden until location_type is
 *      backfilled for the real store/route/depot codes.
 *
 * Quantities are in the item's base unit (litres, stock_id '0001').
 * Transfers carry no reliable monetary valuation in this data — price and
 * standard_cost are 0 on virtually every 0_stock_moves type=16 row — so the
 * grand totals and the difference (sections 7-9) are computed on QUANTITY
 * only. Purchases and Sales additionally report a monetary `amount` where
 * the data supports it.
 */
class FarmerSupplierStatementController extends Controller
{
    private const P = '0_';
    private const ITEM = '0001';

    private const LOCATION_TYPES = [
        1 => 'Transporter',
        2 => 'Cooling Center',
        3 => 'Main Factory',
        4 => 'Collection Center',
    ];

    /** Internal-use locations that absorb milk without it being a sale or a normal transfer. */
    private const KITCHEN_CODES  = ['KT001'];
    private const REJECT_CODES   = ['RJ001'];
    private const SPILLAGE_CODES = ['S004', 'SP001'];

    private function fmt(string $date): string
    {
        return date('Y-m-d', strtotime($date));
    }

    /** Live (today-inclusive) ranges are never cached; historical ranges are immutable. */
    private function isLive(string $to): bool
    {
        return $to >= date('Y-m-d');
    }

    private function tareSupplierIds(): array
    {
        return Cache::remember('fss_tare_supplier_ids', 300, function () {
            return DB::table(self::P . 'suppliers')
                ->where('member_no', '0')
                ->pluck('supplier_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        });
    }

    /**
     * GET /api/milk-collection/farmer-supplier-statement
     *   ?loc_code=T080&from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * `loc_code` is optional — when omitted (or blank), the statement is
     * computed system-wide across every store for the date range instead of
     * being scoped to one.
     */
    public function statement(Request $request): JsonResponse
    {
        $request->validate([
            'loc_code' => 'nullable|string|max:20',
            'from'     => 'required|date',
            'to'       => 'required|date|after_or_equal:from',
        ]);

        $loc     = trim((string) $request->loc_code);
        $hasLoc  = $loc !== '';
        $from    = $this->fmt($request->from);
        $to      = $this->fmt($request->to);
        $P       = self::P;

        $cacheKey = 'farmer_supplier_statement_' . ($hasLoc ? $loc : 'ALL') . "_{$from}_{$to}";
        if (!$this->isLive($to) && ($cached = Cache::get($cacheKey))) {
            return ApiResponse::success($cached, 'Statement retrieved');
        }

        try {
            // ── 1. Purchases from farmers ────────────────────────────────────
            $tareIn = implode(',', $this->tareSupplierIds() ?: [0]);
            $poLocFilter = $hasLoc ? 'AND po.into_stock_location = ?' : '';
            $purchaseRow = DB::selectOne("
                SELECT
                    ROUND(SUM(pod.quantity_ordered), 2)                  AS qty,
                    ROUND(SUM(pod.quantity_ordered * pod.unit_price), 2) AS amount
                FROM {$P}purch_orders po
                STRAIGHT_JOIN {$P}purch_order_details pod
                           ON pod.order_no = po.order_no AND pod.item_code = '" . self::ITEM . "'
                WHERE po.ord_date BETWEEN ? AND ?
                  {$poLocFilter}
                  AND po.supplier_id NOT IN ($tareIn)
            ", $hasLoc ? [$from, $to, $loc] : [$from, $to]);

            $purchases = [
                'qty'    => round((float) ($purchaseRow->qty ?? 0), 2),
                'amount' => round((float) ($purchaseRow->amount ?? 0), 2),
            ];

            // ── 2-4. Transfers in, grouped by source location + its type ─────
            $smLocFilter = $hasLoc ? 'AND sm.loc_code = ?' : '';
            $transfersInRows = DB::select("
                SELECT
                    sm.loc_code_from AS code,
                    l.location_name  AS name,
                    l.location_type  AS loc_type,
                    ROUND(SUM(sm.qty), 2) AS qty
                FROM {$P}stock_moves sm
                LEFT JOIN {$P}locations l ON l.loc_code = sm.loc_code_from
                WHERE sm.type = 16 AND sm.qty > 0
                  {$smLocFilter}
                  AND sm.tran_date BETWEEN ? AND ?
                GROUP BY sm.loc_code_from, l.location_name, l.location_type
                ORDER BY qty DESC
            ", $hasLoc ? [$loc, $from, $to] : [$from, $to]);

            $transfersIn = $this->bucketByLocationType($transfersInRows);

            // ── 5. Sales, broken down by customer ─────────────────────────────
            $moveLocFilter = $hasLoc ? 'AND move.loc_code = ?' : '';
            $salesRows = DB::select("
                SELECT
                    trans.debtor_no AS code,
                    debtor.name     AS name,
                    ROUND(SUM(-move.qty), 2)              AS qty,
                    ROUND(SUM(-move.qty * move.price), 2) AS amount
                FROM {$P}stock_moves    move
                JOIN {$P}debtor_trans   trans  ON trans.trans_no = move.trans_no AND trans.type = move.type
                JOIN {$P}debtors_master debtor ON debtor.debtor_no = trans.debtor_no
                WHERE move.type IN (13, 11)
                  {$moveLocFilter}
                  AND move.tran_date BETWEEN ? AND ?
                GROUP BY trans.debtor_no, debtor.name
                ORDER BY qty DESC
            ", $hasLoc ? [$loc, $from, $to] : [$from, $to]);

            $salesTotalQty = round(collect($salesRows)->sum('qty'), 2);
            $salesTotalAmt = round(collect($salesRows)->sum('amount'), 2);

            // ── 6. Transfer out, broken down by destination store ─────────────
            $oLocFilter = $hasLoc ? 'AND o.loc_code = ?' : '';
            $transferOutRows = DB::select("
                SELECT
                    i.loc_code       AS code,
                    il.location_name AS name,
                    ROUND(SUM(-o.qty), 2) AS qty
                FROM {$P}stock_moves o
                JOIN {$P}stock_moves i  ON i.trans_no = o.trans_no AND i.type = 16
                                       AND i.qty > 0 AND i.stock_id = o.stock_id
                LEFT JOIN {$P}locations il ON il.loc_code = i.loc_code
                WHERE o.type = 16 AND o.qty < 0
                  {$oLocFilter}
                  AND o.tran_date BETWEEN ? AND ?
                GROUP BY i.loc_code, il.location_name
                ORDER BY qty DESC
            ", $hasLoc ? [$loc, $from, $to] : [$from, $to]);

            $transferOutTotalQty = round(collect($transferOutRows)->sum('qty'), 2);

            // ── 7-9. Grand totals & difference (quantity — see class docblock) ─
            $grandInQty  = round($purchases['qty'] + $transfersIn['total_qty'], 2);
            $grandOutQty = round($salesTotalQty + $transferOutTotalQty, 2);
            $difference  = round($grandInQty - $grandOutQty, 2);

            // ── Qty used in manufacturing ──────────────────────────────────────
            // type=29 (ST_MANURECEIVE) is where this business books raw-milk
            // consumption for production (UHT/yoghurt lines) — negative qty on
            // the milk (stock_id '0001') leg of the work order.
            $mfgLocFilter = $hasLoc ? 'AND loc_code = ?' : '';
            $mfgRow = DB::selectOne("
                SELECT ROUND(SUM(-qty), 2) AS qty
                FROM {$P}stock_moves
                WHERE stock_id = '" . self::ITEM . "' AND type = 29 AND qty < 0
                  {$mfgLocFilter}
                  AND tran_date BETWEEN ? AND ?
            ", $hasLoc ? [$loc, $from, $to] : [$from, $to]);
            $manufacturingQty = round((float) ($mfgRow->qty ?? 0), 2);

            // ── Consumables: milk transferred out to internal-use locations ────
            // (kitchen, spillage/damage, reject stores) rather than sold or
            // moved to another operating store. Same self-join as transfer_out,
            // narrowed to those destination codes.
            $consumableCodes = array_merge(self::KITCHEN_CODES, self::REJECT_CODES, self::SPILLAGE_CODES);
            $consumablePlaceholders = implode(',', array_fill(0, count($consumableCodes), '?'));
            $consumableOLocFilter = $hasLoc ? 'AND o.loc_code = ?' : '';
            $consumableRows = DB::select("
                SELECT i.loc_code AS code, ROUND(SUM(-o.qty), 2) AS qty
                FROM {$P}stock_moves o
                JOIN {$P}stock_moves i ON i.trans_no = o.trans_no AND i.type = 16
                                      AND i.qty > 0 AND i.stock_id = o.stock_id
                WHERE o.type = 16 AND o.qty < 0 AND o.stock_id = '" . self::ITEM . "'
                  {$consumableOLocFilter}
                  AND i.loc_code IN ($consumablePlaceholders)
                  AND o.tran_date BETWEEN ? AND ?
                GROUP BY i.loc_code
            ", $hasLoc ? [$loc, ...$consumableCodes, $from, $to] : [...$consumableCodes, $from, $to]);

            $kitchenQty  = 0.0;
            $rejectQty   = 0.0;
            $spillageQty = 0.0;
            foreach ($consumableRows as $r) {
                $qty = (float) $r->qty;
                if (in_array($r->code, self::KITCHEN_CODES, true))  $kitchenQty  += $qty;
                if (in_array($r->code, self::REJECT_CODES, true))   $rejectQty   += $qty;
                if (in_array($r->code, self::SPILLAGE_CODES, true)) $spillageQty += $qty;
            }
            $consumables = [
                'kitchen_qty'  => round($kitchenQty, 2),
                'reject_qty'   => round($rejectQty, 2),
                'spillage_qty' => round($spillageQty, 2),
                'total_qty'    => round($kitchenQty + $rejectQty + $spillageQty, 2),
            ];

            $data = [
                'loc_code'     => $hasLoc ? $loc : null,
                'all_stores'   => !$hasLoc,
                'from'         => $from,
                'to'           => $to,
                'purchases'    => $purchases,
                'transfers_in' => $transfersIn,
                'sales'        => [
                    'rows'         => $salesRows,
                    'total_qty'    => $salesTotalQty,
                    'total_amount' => $salesTotalAmt,
                ],
                'transfer_out' => [
                    'rows'      => $transferOutRows,
                    'total_qty' => $transferOutTotalQty,
                ],
                'grand_total_in_qty'  => $grandInQty,
                'grand_total_out_qty' => $grandOutQty,
                'difference_qty'      => $difference,
                'stats' => [
                    'total_collections_qty' => $purchases['qty'],
                    'total_deliveries_qty'  => $transfersIn['total_qty'],
                    'total_sales_qty'       => $salesTotalQty,
                    'manufacturing_qty'     => $manufacturingQty,
                    'consumables'           => $consumables,
                ],
            ];

            if (!$this->isLive($to)) {
                Cache::put($cacheKey, $data, 86400 * 30);
            }

            return ApiResponse::success($data, 'Statement retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Statement query failed: ' . $e->getMessage());
        }
    }

    /**
     * Buckets transfer-in rows by 0_locations.location_type into named
     * categories, plus an "unclassified" bucket for anything not tagged —
     * surfaced rather than silently dropped, since most locations in this
     * database don't have location_type set yet.
     */
    private function bucketByLocationType(array $rows): array
    {
        $buckets = [];
        foreach (self::LOCATION_TYPES as $type => $label) {
            $buckets[$type] = ['label' => $label, 'rows' => [], 'total_qty' => 0.0];
        }
        $buckets['unclassified'] = ['label' => 'Unclassified', 'rows' => [], 'total_qty' => 0.0];

        $totalQty = 0.0;
        foreach ($rows as $r) {
            $qty = (float) $r->qty;
            $totalQty += $qty;

            $type = $r->loc_type;
            $key  = ($type !== null && isset(self::LOCATION_TYPES[(int) $type])) ? (int) $type : 'unclassified';

            $buckets[$key]['rows'][]     = ['code' => $r->code, 'name' => $r->name ?? $r->code, 'qty' => round($qty, 2)];
            $buckets[$key]['total_qty'] += $qty;
        }
        foreach ($buckets as &$b) {
            $b['total_qty'] = round($b['total_qty'], 2);
        }
        unset($b);

        return [
            'transporter'       => $buckets[1],
            'collection_center' => $buckets[4],
            'cooling_center'    => $buckets[2],
            'main_factory'      => $buckets[3],
            'unclassified'      => $buckets['unclassified'],
            'total_qty'         => round($totalQty, 2),
        ];
    }
}
