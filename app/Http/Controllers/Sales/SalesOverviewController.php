<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\ReadsFromKirima;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/sales/overview
 *
 * At-a-glance sales: invoices vs payments, credit notes, order conversion,
 * top item, sales by stock category, 7-day + monthly (YTD) trends.
 */
class SalesOverviewController extends Controller
{
    use ReadsFromKirima;

    private const P = '0_';

    public function overview(): JsonResponse
    {
        $today   = date('Y-m-d');
        $yearStart = date('Y-01-01');
        $weekFrom  = date('Y-m-d', strtotime('-6 days'));
        $mtdFrom   = date('Y-m-01');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $prevMonthStart = date('Y-m-01', strtotime('first day of last month'));
        $dayOfMonth     = (int) date('j');
        $prevMonthDays  = (int) date('t', strtotime($prevMonthStart));
        $prevComparableEnd = date(
            'Y-m-d',
            strtotime($prevMonthStart . ' +' . (min($dayOfMonth, $prevMonthDays) - 1) . ' days')
        );

        $cacheKey = "sales_overview_v1_{$today}";
        if ($cached = Cache::get($cacheKey)) {
            return ApiResponse::success($cached, 'Sales overview retrieved');
        }

        $p = self::P;

        try {
            $delta = function (float $curr, float $prev, int $currCount = 0, int $prevCount = 0): array {
                $abs = round($curr - $prev, 2);
                $pct = $prev > 0
                    ? round(($abs / $prev) * 100, 1)
                    : ($curr > 0 ? 100.0 : 0.0);
                return [
                    'value'      => round($curr, 2),
                    'prev'       => round($prev, 2),
                    'delta'      => $abs,
                    'delta_pct'  => $pct,
                    'up'         => $abs >= 0,
                    'count'      => $currCount,
                    'prev_count' => $prevCount,
                ];
            };

            $periodSum = function (string $from, string $to, int $type) use ($p): array {
                $row = $this->kirima()->selectOne("
                    SELECT
                        COALESCE(SUM(ABS(ov_amount)), 0) AS total,
                        COUNT(*) AS cnt
                    FROM {$p}debtor_trans
                    WHERE type = ?
                      AND tran_date BETWEEN ? AND ?
                ", [$type, $from, $to]);
                return [
                    'total' => (float) ($row->total ?? 0),
                    'count' => (int) ($row->cnt ?? 0),
                ];
            };

            // ── KPIs: invoices (10), payments (12), credit notes (11) ────────
            $invToday = $periodSum($today, $today, 10);
            $invYest  = $periodSum($yesterday, $yesterday, 10);
            $invMtd   = $periodSum($mtdFrom, $today, 10);
            $invPrev  = $periodSum($prevMonthStart, $prevComparableEnd, 10);

            $payToday = $periodSum($today, $today, 12);
            $payYest  = $periodSum($yesterday, $yesterday, 12);
            $payMtd   = $periodSum($mtdFrom, $today, 12);
            $payPrev  = $periodSum($prevMonthStart, $prevComparableEnd, 12);

            $cnMtd  = $periodSum($mtdFrom, $today, 11);
            $cnPrev = $periodSum($prevMonthStart, $prevComparableEnd, 11);

            // ── Order conversion (sales_orders → invoiced) ───────────────────
            $conv = $this->kirima()->selectOne("
                SELECT
                    COUNT(*) AS orders,
                    SUM(CASE WHEN inv.order_no IS NOT NULL THEN 1 ELSE 0 END) AS invoiced_orders
                FROM {$p}sales_orders so
                LEFT JOIN (
                    SELECT DISTINCT order_ AS order_no
                    FROM {$p}debtor_trans
                    WHERE type = 10
                      AND order_ > 0
                      AND tran_date BETWEEN ? AND ?
                ) inv ON inv.order_no = so.order_no
                WHERE so.ord_date BETWEEN ? AND ?
            ", [$mtdFrom, $today, $mtdFrom, $today]);

            $orders = (int) ($conv->orders ?? 0);
            $invoicedOrders = (int) ($conv->invoiced_orders ?? 0);
            $conversionPct = $orders > 0
                ? round(($invoicedOrders / $orders) * 100, 1)
                : 0.0;

            // ── Most sold item (invoice lines, stock goods) ──────────────────
            $topItemRow = $this->kirima()->selectOne("
                SELECT
                    dtd.stock_id,
                    sm.description,
                    sc.description AS category_name,
                    ROUND(SUM(dtd.quantity), 3) AS qty,
                    ROUND(SUM(dtd.quantity * dtd.unit_price), 2) AS amount
                FROM {$p}debtor_trans t
                JOIN {$p}debtor_trans_details dtd
                  ON dtd.debtor_trans_no = t.trans_no
                 AND dtd.debtor_trans_type = t.type
                JOIN {$p}stock_master sm ON sm.stock_id = dtd.stock_id
                LEFT JOIN {$p}stock_category sc ON sc.category_id = sm.category_id
                WHERE t.type = 10
                  AND t.tran_date BETWEEN ? AND ?
                  AND sm.mb_flag NOT IN ('D', 'F')
                GROUP BY dtd.stock_id, sm.description, sc.description
                ORDER BY qty DESC
                LIMIT 1
            ", [$mtdFrom, $today]);

            $topItem = $topItemRow ? [
                'stock_id'      => $topItemRow->stock_id,
                'description'   => $topItemRow->description,
                'category_name' => $topItemRow->category_name,
                'qty'           => (float) $topItemRow->qty,
                'amount'        => (float) $topItemRow->amount,
            ] : null;

            // ── Sales by purchased/stock category (MTD) ──────────────────────
            $catRows = $this->kirima()->select("
                SELECT
                    sm.category_id,
                    COALESCE(sc.description, CONCAT('Category ', sm.category_id)) AS category_name,
                    ROUND(SUM(dtd.quantity), 3) AS qty,
                    ROUND(SUM(dtd.quantity * dtd.unit_price), 2) AS amount
                FROM {$p}debtor_trans t
                JOIN {$p}debtor_trans_details dtd
                  ON dtd.debtor_trans_no = t.trans_no
                 AND dtd.debtor_trans_type = t.type
                JOIN {$p}stock_master sm ON sm.stock_id = dtd.stock_id
                LEFT JOIN {$p}stock_category sc ON sc.category_id = sm.category_id
                WHERE t.type = 10
                  AND t.tran_date BETWEEN ? AND ?
                  AND sm.mb_flag NOT IN ('D', 'F')
                GROUP BY sm.category_id, sc.description
                ORDER BY amount DESC
            ", [$mtdFrom, $today]);

            $byCategory = array_map(fn($r) => [
                'category_id'   => (int) $r->category_id,
                'category_name' => $r->category_name,
                'qty'           => (float) $r->qty,
                'amount'        => (float) $r->amount,
            ], $catRows);

            // ── Last 7 days trend (invoices / payments / credit notes) ───────
            $dailyRows = $this->kirima()->select("
                SELECT
                    tran_date,
                    type,
                    ROUND(SUM(ABS(ov_amount)), 2) AS total
                FROM {$p}debtor_trans
                WHERE type IN (10, 11, 12)
                  AND tran_date BETWEEN ? AND ?
                GROUP BY tran_date, type
                ORDER BY tran_date ASC
            ", [$weekFrom, $today]);

            $byDay = [];
            foreach ($dailyRows as $r) {
                $d = $r->tran_date;
                if (!isset($byDay[$d])) {
                    $byDay[$d] = ['date' => $d, 'invoiced' => 0.0, 'paid' => 0.0, 'credit_notes' => 0.0];
                }
                $amt = (float) $r->total;
                if ((int) $r->type === 10) $byDay[$d]['invoiced'] = $amt;
                if ((int) $r->type === 12) $byDay[$d]['paid'] = $amt;
                if ((int) $r->type === 11) $byDay[$d]['credit_notes'] = $amt;
            }

            $trend7 = [];
            for ($i = 0; $i < 7; $i++) {
                $d = date('Y-m-d', strtotime("{$weekFrom} +{$i} days"));
                $trend7[] = $byDay[$d] ?? ['date' => $d, 'invoiced' => 0.0, 'paid' => 0.0, 'credit_notes' => 0.0];
            }

            // ── Monthly trend (this year) ────────────────────────────────────
            $monthRows = $this->kirima()->select("
                SELECT
                    DATE_FORMAT(tran_date, '%Y-%m') AS ym,
                    type,
                    ROUND(SUM(ABS(ov_amount)), 2) AS total
                FROM {$p}debtor_trans
                WHERE type IN (10, 11, 12)
                  AND tran_date BETWEEN ? AND ?
                GROUP BY DATE_FORMAT(tran_date, '%Y-%m'), type
                ORDER BY ym ASC
            ", [$yearStart, $today]);

            $byMonth = [];
            foreach ($monthRows as $r) {
                $m = $r->ym;
                if (!isset($byMonth[$m])) {
                    $byMonth[$m] = ['month' => $m, 'invoiced' => 0.0, 'paid' => 0.0, 'credit_notes' => 0.0];
                }
                $amt = (float) $r->total;
                if ((int) $r->type === 10) $byMonth[$m]['invoiced'] = $amt;
                if ((int) $r->type === 12) $byMonth[$m]['paid'] = $amt;
                if ((int) $r->type === 11) $byMonth[$m]['credit_notes'] = $amt;
            }

            $trendMonthly = [];
            $cursor = strtotime($yearStart);
            $endTs  = strtotime(date('Y-m-01', strtotime($today)));
            while ($cursor <= $endTs) {
                $ym = date('Y-m', $cursor);
                $trendMonthly[] = $byMonth[$ym] ?? ['month' => $ym, 'invoiced' => 0.0, 'paid' => 0.0, 'credit_notes' => 0.0];
                $cursor = strtotime('+1 month', $cursor);
            }

            $collectionRate = $invMtd['total'] > 0
                ? round(min(100, ($payMtd['total'] / $invMtd['total']) * 100), 1)
                : 0.0;

            $data = [
                'as_of' => $today,
                'invoices' => [
                    'today' => $delta($invToday['total'], $invYest['total'], $invToday['count'], $invYest['count']),
                    'month' => $delta($invMtd['total'], $invPrev['total'], $invMtd['count'], $invPrev['count']),
                ],
                'payments' => [
                    'today' => $delta($payToday['total'], $payYest['total'], $payToday['count'], $payYest['count']),
                    'month' => $delta($payMtd['total'], $payPrev['total'], $payMtd['count'], $payPrev['count']),
                ],
                'credit_notes' => [
                    'month' => $delta($cnMtd['total'], $cnPrev['total'], $cnMtd['count'], $cnPrev['count']),
                ],
                'collection_rate' => $collectionRate,
                'order_conversion' => [
                    'orders'          => $orders,
                    'invoiced_orders' => $invoicedOrders,
                    'rate_pct'        => $conversionPct,
                ],
                'top_item'      => $topItem,
                'by_category'   => $byCategory,
                'trend7'        => $trend7,
                'trend_monthly' => $trendMonthly,
            ];

            Cache::put($cacheKey, $data, 300);

            return ApiResponse::success($data, 'Sales overview retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Sales overview failed: ' . $e->getMessage());
        }
    }
}
