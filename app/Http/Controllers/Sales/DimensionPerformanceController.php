<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DimensionPerformanceController extends Controller
{
    private string $p = '0_';   // table prefix

    /**
     * GET /api/sales/dimensions/summary
     * High-level: each dimension's total invoiced, paid, balance, invoice count.
     */
    public function summary(Request $request): JsonResponse
    {
        $from = $request->query('from', date('Y-m-01'));
        $to   = $request->query('to',   date('Y-m-d'));
        $p    = $this->p;

        $cacheKey = "dim_summary_{$from}_{$to}";
        if ($cached = Cache::get($cacheKey)) {
            return ApiResponse::success($cached, 'Dimension summary retrieved');
        }

        // Match FrontAccounting rep129 behaviour: attribute the full invoice
        // ov_amount to a dimension whenever ANY item on that invoice belongs
        // to it. We first deduplicate invoices per dimension (a single invoice
        // could have items from multiple dimensions and must only be counted
        // once per dimension), then sum the header ov_amount and alloc.
        $rows = DB::select("
            SELECT
                d.id            AS dimension_id,
                d.name          AS dimension_name,
                d.reference,
                COUNT(*)                        AS invoice_count,
                COALESCE(SUM(inv.ov_amount), 0) AS total_invoiced,
                COALESCE(SUM(inv.alloc), 0)     AS total_paid
            FROM {$p}dimensions d
            JOIN (
                SELECT DISTINCT t.trans_no, t.ov_amount, t.alloc, sm.dimension_id
                FROM {$p}debtor_trans          t
                JOIN {$p}debtor_trans_details  dtd ON dtd.debtor_trans_no   = t.trans_no
                                                   AND dtd.debtor_trans_type = t.type
                JOIN {$p}stock_master          sm  ON sm.stock_id            = dtd.stock_id
                WHERE t.tran_date BETWEEN ? AND ?
                  AND t.type = 10
                  AND sm.dimension_id IS NOT NULL
                  AND sm.dimension_id != 0
            ) inv ON inv.dimension_id = d.id
            GROUP BY d.id, d.name, d.reference
            ORDER BY total_invoiced DESC
        ", [$from, $to]);

        $data = array_map(fn($r) => [
            'dimension_id'   => (int) $r->dimension_id,
            'dimension_name' => $r->dimension_name,
            'reference'      => $r->reference,
            'invoice_count'  => (int) $r->invoice_count,
            'total_invoiced' => round((float) $r->total_invoiced, 2),
            'total_paid'     => round((float) $r->total_paid, 2),
            'balance'        => round((float) $r->total_invoiced - (float) $r->total_paid, 2),
        ], $rows);

        $ttl = $to === date('Y-m-d') ? 300 : 1800;
        Cache::put($cacheKey, $data, $ttl);

        return ApiResponse::success($data, 'Dimension summary retrieved');
    }

    /**
     * GET /api/sales/dimensions/daily
     * Daily sales totals per dimension for chart rendering.
     */
    public function daily(Request $request): JsonResponse
    {
        $from = $request->query('from', date('Y-m-01'));
        $to   = $request->query('to',   date('Y-m-d'));
        $p    = $this->p;

        $cacheKey = "dim_daily_{$from}_{$to}";
        if ($cached = Cache::get($cacheKey)) {
            return ApiResponse::success($cached, 'Dimension daily data retrieved');
        }

        $rows = DB::select("
            SELECT
                d.id              AS dimension_id,
                d.name            AS dimension_name,
                DATE(t.tran_date) AS sale_date,
                COALESCE(SUM(dtd.quantity * dtd.unit_price), 0) AS daily_amount
            FROM {$p}dimensions d
            INNER JOIN {$p}stock_master sm
                ON sm.dimension_id = d.id
            INNER JOIN {$p}debtor_trans_details dtd
                ON dtd.stock_id = sm.stock_id
            INNER JOIN {$p}debtor_trans t
                ON  t.trans_no          = dtd.debtor_trans_no
                AND t.type              = dtd.debtor_trans_type
                AND t.tran_date BETWEEN ? AND ?
                AND t.type = 10
            GROUP BY d.id, d.name, DATE(t.tran_date)
            ORDER BY d.id, sale_date
        ", [$from, $to]);

        $data = array_map(fn($r) => [
            'dimension_id'   => (int) $r->dimension_id,
            'dimension_name' => $r->dimension_name,
            'sale_date'      => $r->sale_date,
            'daily_amount'   => round((float) $r->daily_amount, 2),
        ], $rows);

        $ttl = $to === date('Y-m-d') ? 300 : 1800;
        Cache::put($cacheKey, $data, $ttl);

        return ApiResponse::success($data, 'Dimension daily data retrieved');
    }

    /**
     * GET /api/sales/customers
     * Dropdown list of customers who have invoices.
     */
    public function customers(Request $request): JsonResponse
    {
        if ($cached = Cache::get('dim_customers')) {
            return ApiResponse::success($cached, 'Customers retrieved');
        }
        $p    = $this->p;
        $rows = DB::select("
            SELECT dm.debtor_no, dm.name
            FROM {$p}debtors_master dm
            WHERE EXISTS (
                SELECT 1 FROM {$p}debtor_trans t
                WHERE t.debtor_no = dm.debtor_no AND t.type = 10
            )
            ORDER BY dm.name
        ");
        $data = array_map(fn($r) => ['debtor_no' => $r->debtor_no, 'name' => $r->name], $rows);
        Cache::put('dim_customers', $data, 600);
        return ApiResponse::success($data, 'Customers retrieved');
    }

    /**
     * Build WHERE clauses and bindings for the shared report filters.
     * Matches the PHP report: pay_terms, customer, location, category, item.
     */
    private function buildFilters(Request $request, string $p, string $tAlias = 't', string $dtdAlias = 'dtd', string $smAlias = 'sm'): array
    {
        $where    = [];
        $bindings = [];

        // ── Payment terms: 2 = Cash (paid > 0), 3 = Credit (paid <= 0) ──
        $payTerms = $request->query('pay_terms', '1');
        if ($payTerms === '2') {
            // Cash sales: at least some payment has been allocated
            $where[] = "(SELECT COALESCE(SUM(a.amt),0) FROM {$p}cust_allocations a WHERE a.trans_no_to={$tAlias}.trans_no AND a.trans_type_to={$tAlias}.type) > 0";
        } elseif ($payTerms === '3') {
            // Credit sales: nothing allocated yet
            $where[] = "(SELECT COALESCE(SUM(a.amt),0) FROM {$p}cust_allocations a WHERE a.trans_no_to={$tAlias}.trans_no AND a.trans_type_to={$tAlias}.type) <= 0";
        }

        // ── Customer ──
        $customer = $request->query('customer', '');
        if ($customer !== '') {
            $where[]    = "{$tAlias}.debtor_no = ?";
            $bindings[] = $customer;
        }

        // ── Location (store) ──
        $location = $request->query('location', '');
        if ($location !== '') {
            $where[]    = "{$tAlias}.order_ IN (SELECT order_no FROM {$p}sales_orders WHERE from_stk_loc = ?)";
            $bindings[] = $location;
        }

        // ── Inventory category ──
        $category = $request->query('category', '');
        if ($category !== '') {
            $where[]    = "{$smAlias}.category_id = ?";
            $bindings[] = $category;
        }

        // ── Specific item ──
        $item = $request->query('item', '');
        if ($item !== '') {
            $where[]    = "{$dtdAlias}.stock_id = ?";
            $bindings[] = $item;
        }

        $sql = $where ? ('AND ' . implode(' AND ', $where)) : '';
        return [$sql, $bindings];
    }

    /**
     * GET /api/sales/dimensions/{id}/items
     * Drill-down: items sold within a specific dimension.
     */
    public function items(Request $request, int $id): JsonResponse
    {
        $from = $request->query('from', date('Y-m-01'));
        $to   = $request->query('to',   date('Y-m-d'));
        $p    = $this->p;

        [$filterSql, $filterBindings] = $this->buildFilters($request, $p);

        $rows = DB::select("
            SELECT
                dtd.stock_id,
                COALESCE(NULLIF(dtd.description, ''), sm.description) AS description,
                SUM(dtd.quantity)                                       AS quantity,
                SUM(dtd.quantity * dtd.unit_price)                     AS total_amount,
                COUNT(DISTINCT t.trans_no)                             AS invoice_count,
                COALESCE(SUM(
                    CASE WHEN t.ov_amount > 0
                         THEN t.alloc * (dtd.quantity * dtd.unit_price) / t.ov_amount
                         ELSE 0
                    END
                ), 0)                                                   AS paid_amount
            FROM {$p}debtor_trans t
            INNER JOIN {$p}debtor_trans_details dtd
                ON  dtd.debtor_trans_no   = t.trans_no
                AND dtd.debtor_trans_type = t.type
            INNER JOIN {$p}stock_master sm
                ON  sm.stock_id     = dtd.stock_id
                AND sm.dimension_id = ?
            WHERE t.tran_date BETWEEN ? AND ?
              AND t.type = 10
              $filterSql
            GROUP BY dtd.stock_id, dtd.description, sm.description
            ORDER BY total_amount DESC
        ", array_merge([$id, $from, $to], $filterBindings));

        $data = array_map(fn($r) => [
            'stock_id'      => $r->stock_id,
            'description'   => $r->description,
            'quantity'      => round((float) $r->quantity, 2),
            'total_amount'  => round((float) $r->total_amount, 2),
            'invoice_count' => (int) $r->invoice_count,
            'paid_amount'   => round((float) $r->paid_amount, 2),
            'balance'       => round((float) $r->total_amount - (float) $r->paid_amount, 2),
        ], $rows);

        return ApiResponse::success($data, 'Dimension items retrieved');
    }

    /**
     * GET /api/sales/dimensions/{id}/transactions
     * Full invoice listing matching the ERP Sales Analysis Report ($sql):
     * one row per invoice+item, grouped into invoices with nested items.
     * paid_amount uses a correlated subquery per invoice (exact, not proportional).
     */
    public function transactions(Request $request, int $id): JsonResponse
    {
        $from = $request->query('from', date('Y-m-01'));
        $to   = $request->query('to',   date('Y-m-d'));
        $p    = $this->p;

        [$filterSql, $filterBindings] = $this->buildFilters($request, $p);

        $rows = DB::select("
            SELECT
                t.trans_no,
                t.reference,
                t.tran_date,
                t.ov_amount,
                dm.name                                                     AS customer_name,
                COALESCE(st.sales_type, '')                                 AS pricing_type,
                dtd.stock_id,
                COALESCE(NULLIF(dtd.description, ''), sm.description)       AS description,
                dtd.unit_price,
                dtd.quantity,
                dtd.quantity * dtd.unit_price                               AS line_total,
                (SELECT COALESCE(SUM(amt), 0)
                 FROM {$p}cust_allocations
                 WHERE trans_no_to   = t.trans_no
                   AND trans_type_to = t.type)                              AS paid_amount
            FROM {$p}debtor_trans          t
            JOIN {$p}debtor_trans_details  dtd ON dtd.debtor_trans_no   = t.trans_no
                                              AND dtd.debtor_trans_type = t.type
            JOIN {$p}stock_master          sm  ON sm.stock_id           = dtd.stock_id
                                              AND sm.dimension_id       = ?
            JOIN {$p}debtors_master        dm  ON dm.debtor_no          = t.debtor_no
            LEFT JOIN {$p}sales_types      st  ON st.id                 = t.tpe
            WHERE t.tran_date BETWEEN ? AND ?
              AND t.type = 10
              $filterSql
            ORDER BY t.tran_date, t.trans_no, dtd.id
        ", array_merge([$id, $from, $to], $filterBindings));

        // Group flat rows into invoices with nested item arrays
        $invoices = [];
        foreach ($rows as $row) {
            $key = $row->trans_no;
            if (!isset($invoices[$key])) {
                $invAmt  = round((float) $row->ov_amount,    2);
                $paid    = round((float) $row->paid_amount,  2);
                $invoices[$key] = [
                    'trans_no'     => (int) $row->trans_no,
                    'reference'    => $row->reference,
                    'tran_date'    => $row->tran_date,
                    'customer'     => $row->customer_name,
                    'pricing_type' => $row->pricing_type,
                    'ov_amount'    => $invAmt,
                    'paid_amount'  => $paid,
                    'balance'      => round($invAmt - $paid, 2),
                    'items'        => [],
                ];
            }
            $invoices[$key]['items'][] = [
                'stock_id'    => $row->stock_id,
                'description' => $row->description,
                'unit_price'  => round((float) $row->unit_price, 2),
                'quantity'    => round((float) $row->quantity,   2),
                'line_total'  => round((float) $row->line_total, 2),
            ];
        }

        return ApiResponse::success(array_values($invoices), 'Dimension transactions retrieved');
    }

    /**
     * GET /api/sales/dimensions/{id}/payments
     * Payments summary matching $sqly + $sqlz:
     * bank account breakdown of payments + credit note totals.
     */
    public function payments(Request $request, int $id): JsonResponse
    {
        $from = $request->query('from', date('Y-m-01'));
        $to   = $request->query('to',   date('Y-m-d'));
        $p    = $this->p;

        [$filterSql, $filterBindings] = $this->buildFilters($request, $p);

        // Limit to invoices that contain items from this dimension + active filters
        $dimFilter = "AND t.trans_no IN (
            SELECT DISTINCT dtd2.debtor_trans_no
            FROM {$p}debtor_trans_details dtd2
            JOIN {$p}stock_master sm2 ON sm2.stock_id = dtd2.stock_id AND sm2.dimension_id = ?
        )";

        // Build additional filter SQL that applies at the invoice (t) level only.
        // Category/item filters need to be re-expressed as trans_no IN subqueries.
        $txnWhere    = [];
        $txnBindings = [];

        $payTerms = $request->query('pay_terms', '1');
        if ($payTerms === '2') {
            $txnWhere[] = "(SELECT COALESCE(SUM(a.amt),0) FROM {$p}cust_allocations a WHERE a.trans_no_to=t.trans_no AND a.trans_type_to=t.type) > 0";
        } elseif ($payTerms === '3') {
            $txnWhere[] = "(SELECT COALESCE(SUM(a.amt),0) FROM {$p}cust_allocations a WHERE a.trans_no_to=t.trans_no AND a.trans_type_to=t.type) <= 0";
        }
        $customer = $request->query('customer', '');
        if ($customer !== '') { $txnWhere[] = "t.debtor_no = ?"; $txnBindings[] = $customer; }
        $location = $request->query('location', '');
        if ($location !== '') { $txnWhere[] = "t.order_ IN (SELECT order_no FROM {$p}sales_orders WHERE from_stk_loc = ?)"; $txnBindings[] = $location; }
        $category = $request->query('category', '');
        if ($category !== '') {
            $txnWhere[] = "t.trans_no IN (SELECT DISTINCT dtd3.debtor_trans_no FROM {$p}debtor_trans_details dtd3 JOIN {$p}stock_master sm3 ON sm3.stock_id=dtd3.stock_id WHERE sm3.category_id=?)";
            $txnBindings[] = $category;
        }
        $item = $request->query('item', '');
        if ($item !== '') {
            $txnWhere[] = "t.trans_no IN (SELECT DISTINCT dtd4.debtor_trans_no FROM {$p}debtor_trans_details dtd4 WHERE dtd4.stock_id=?)";
            $txnBindings[] = $item;
        }
        $extraSql = $txnWhere ? ('AND ' . implode(' AND ', $txnWhere)) : '';

        // Bank payments ($sqly)
        $bankRows = DB::select("
            SELECT ba.bank_account_name AS description, SUM(alloc.amt) AS amount
            FROM   {$p}debtor_trans         t
            JOIN   {$p}cust_allocations     alloc ON alloc.trans_no_to    = t.trans_no
                                                 AND alloc.trans_type_to  = t.type
            JOIN   {$p}bank_trans           bk    ON bk.trans_no          = alloc.trans_no_from
                                                 AND bk.type              = alloc.trans_type_from
            JOIN   {$p}bank_accounts        ba    ON ba.id                = bk.bank_act
            WHERE  t.tran_date BETWEEN ? AND ?
              AND  t.type = 10
              $dimFilter
              $extraSql
            GROUP  BY ba.id, ba.bank_account_name
            ORDER  BY amount DESC
        ", array_merge([$from, $to, $id], $txnBindings));

        // Credit notes ($sqlz — trans_type_from = 11)
        $creditRows = DB::select("
            SELECT SUM(alloc.amt) AS amount
            FROM   {$p}debtor_trans     t
            JOIN   {$p}cust_allocations alloc ON alloc.trans_no_to   = t.trans_no
                                             AND alloc.trans_type_to = t.type
            WHERE  t.tran_date BETWEEN ? AND ?
              AND  t.type = 10
              AND  alloc.trans_type_from = 11
              $dimFilter
              $extraSql
            HAVING SUM(alloc.amt) > 0
        ", array_merge([$from, $to, $id], $txnBindings));

        // Outstanding balance — "Credit" row ($invoicesBal in FA rep129).
        // = SUM(ov_amount) - SUM(t.alloc) per distinct dimension invoice.
        // t.alloc is FA's denormalized running-total of all allocations applied,
        // matching the FA report's paid_amount correlated subquery on cust_allocations.
        $balRows = DB::select("
            SELECT
                COALESCE(SUM(inv.ov_amount), 0) AS ov_sum,
                COALESCE(SUM(inv.alloc),     0) AS alloc_sum
            FROM (
                SELECT DISTINCT t.trans_no, t.ov_amount, t.alloc
                FROM {$p}debtor_trans          t
                JOIN {$p}debtor_trans_details  dtd ON dtd.debtor_trans_no   = t.trans_no
                                                   AND dtd.debtor_trans_type = t.type
                JOIN {$p}stock_master          sm  ON sm.stock_id            = dtd.stock_id
                                                   AND sm.dimension_id       = ?
                WHERE t.tran_date BETWEEN ? AND ?
                  AND t.type = 10
                  $extraSql
            ) inv
        ", array_merge([$id, $from, $to], $txnBindings));

        $data = array_map(fn($r) => [
            'type'        => 'bank',
            'description' => $r->description,
            'amount'      => round((float) $r->amount, 2),
        ], $bankRows);

        if (!empty($creditRows) && (float) $creditRows[0]->amount > 0) {
            $data[] = [
                'type'        => 'credit_note',
                'description' => 'Credit Note',
                'amount'      => round((float) $creditRows[0]->amount, 2),
            ];
        }

        $ovSum    = (float) ($balRows[0]->ov_sum    ?? 0);
        $allocSum = (float) ($balRows[0]->alloc_sum ?? 0);
        $balance  = round($ovSum - $allocSum, 2);
        if ($balance > 0) {
            $data[] = [
                'type'        => 'credit',
                'description' => 'Credit',
                'amount'      => $balance,
            ];
        }

        return ApiResponse::success($data, 'Dimension payments retrieved');
    }
}
