<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InventoryKpiController extends Controller
{
    public function index(): JsonResponse
    {
        // ── 1. Total active stock items ───────────────────────────────────────
        $stockItems = DB::table('items')->where('inactive', 0)->count();

        // ── 2. Total inventory value = SUM(qty * price) across all movements ──
        //    Works because OUT movements carry negative qty, so value cancels correctly
        $stockValue = (float) DB::table('stock_movements')->selectRaw('SUM(qty * price)')->value('SUM(qty * price)') ?? 0;

        // ── 3. Low stock alerts: locations where balance < reorder_level ──────
        $lowStock = DB::table('item_reorder_levels as rl')
            ->joinSub(
                DB::table('stock_movements')
                    ->select('stock_id', 'loc_code', DB::raw('SUM(qty) as balance'))
                    ->groupBy('stock_id', 'loc_code'),
                'bal',
                fn($j) => $j->on('bal.stock_id', '=', 'rl.stock_id')
                             ->on('bal.loc_code', '=', 'rl.location_id')
            )
            ->whereRaw('bal.balance < rl.reorder_level')
            ->count();

        // ── 4. Active warehouses ──────────────────────────────────────────────
        $warehouses = DB::table('inventory_locations')->count();

        // ── 5. Top 10 items by stock value for the item master table ──────────
        $topItems = DB::table('stock_movements as sm')
            ->join('items as i', 'i.stock_id', '=', 'sm.stock_id')
            ->select(
                'sm.stock_id',
                'i.description',
                DB::raw('COALESCE(c.description, \'\') as category'),
                DB::raw('SUM(sm.qty) as total_qty'),
                DB::raw('i.units as unit'),
                DB::raw('CASE WHEN SUM(sm.qty) != 0 THEN SUM(sm.qty * sm.price) / SUM(sm.qty) ELSE 0 END as avg_price'),
                DB::raw('SUM(sm.qty * sm.price) as total_value')
            )
            ->leftJoin('item_categories as c', 'c.id', '=', 'i.category_id')
            ->groupBy('sm.stock_id', 'i.description', 'c.description', 'i.units')
            ->orderByDesc('total_value')
            ->limit(10)
            ->get();

        return ApiResponse::success([
            'stock_items'      => $stockItems,
            'stock_value'      => $stockValue,
            'low_stock_alerts' => $lowStock,
            'warehouses'       => $warehouses,
            'top_items'        => $topItems,
        ], 'Inventory KPIs');
    }
}
