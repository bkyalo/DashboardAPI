<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Item;
use App\Models\ItemSalesPrice;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    // ── Item lookup by barcode or stock_id ────────────────────────────────────
    public function lookup(Request $request): JsonResponse
    {
        $q = trim($request->get('q', ''));
        if ($q === '') {
            return ApiResponse::error('Query is required', 400);
        }

        $item = Item::where('inactive', false)
            ->where('no_sale', false)
            ->where(function ($query) use ($q) {
                $query->where('stock_id', $q)
                      ->orWhere('bar_code', $q)
                      ->orWhere('description', 'like', "%{$q}%");
            })
            ->select(['stock_id', 'description', 'units', 'bar_code', 'tax_type_id'])
            ->first();

        if (! $item) {
            return ApiResponse::notFound('Item not found');
        }

        // Attach default selling price (first sales price, any currency/type)
        $price = ItemSalesPrice::where('stock_id', $item->stock_id)
            ->orderBy('id')
            ->value('price') ?? 0;

        return ApiResponse::success([
            'stock_id'    => $item->stock_id,
            'description' => $item->description,
            'units'       => $item->units,
            'bar_code'    => $item->bar_code,
            'price'       => (float) $price,
            'tax_rate'    => 16.0,   // default VAT — extend per tax_type_id later
        ], 'Item found');
    }

    // ── Search items (for manual selection) ──────────────────────────────────
    public function search(Request $request): JsonResponse
    {
        $q = trim($request->get('q', ''));
        $query = Item::where('inactive', false)->where('no_sale', false);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('stock_id', 'like', "%{$q}%")
                    ->orWhere('bar_code', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $items = $query->orderBy('description')
            ->select(['stock_id', 'description', 'units', 'bar_code'])
            ->limit(30)
            ->get()
            ->map(function ($item) {
                $price = ItemSalesPrice::where('stock_id', $item->stock_id)
                    ->orderBy('id')->value('price') ?? 0;
                return array_merge($item->toArray(), [
                    'price'    => (float) $price,
                    'tax_rate' => 16.0,
                ]);
            });

        return ApiResponse::success($items, 'Items retrieved');
    }

    // ── List recent POS sales ─────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $sales = PosTransaction::with('items')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return ApiResponse::paginated($sales, 'Sales retrieved');
    }

    // ── Show single sale (receipt) ────────────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $sale = PosTransaction::with('items')->findOrFail($id);
        return ApiResponse::success($sale, 'Sale retrieved');
    }

    // ── Create a POS sale ─────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name'  => 'nullable|string|max:120',
            'payment_method' => 'required|in:cash,card,mpesa',
            'amount_tendered'=> 'required|numeric|min:0',
            'mpesa_ref'      => 'nullable|string|max:60',
            'notes'          => 'nullable|string',
            'items'          => 'required|array|min:1',
            'items.*.stock_id'       => 'required|string',
            'items.*.description'    => 'required|string',
            'items.*.unit_price'     => 'required|numeric|min:0',
            'items.*.quantity'       => 'required|numeric|min:0.001',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate'       => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $subtotal = 0;
            $taxTotal = 0;

            $lineItems = [];
            foreach ($data['items'] as $row) {
                $disc     = $row['discount_percent'] ?? 0;
                $taxRate  = $row['tax_rate'] ?? 0;
                $gross    = round($row['unit_price'] * $row['quantity'], 4);
                $discAmt  = round($gross * $disc / 100, 4);
                $net      = $gross - $discAmt;
                $tax      = round($net * $taxRate / 100, 4);
                $lineTotal = round($net + $tax, 2);

                $subtotal += $net;
                $taxTotal += $tax;

                $lineItems[] = [
                    'stock_id'        => $row['stock_id'],
                    'description'     => $row['description'],
                    'unit_price'      => $row['unit_price'],
                    'quantity'        => $row['quantity'],
                    'discount_percent'=> $disc,
                    'tax_rate'        => $taxRate,
                    'tax_amount'      => $tax,
                    'line_total'      => $lineTotal,
                ];
            }

            $total     = round($subtotal + $taxTotal, 2);
            $tendered  = round($data['amount_tendered'], 2);
            $change    = max(0, $tendered - $total);

            $sale = PosTransaction::create([
                'transaction_no'  => PosTransaction::nextTransactionNo(),
                'cashier_id'      => $request->user()?->user_id,
                'customer_name'   => $data['customer_name'] ?? 'Walk-in Customer',
                'subtotal'        => round($subtotal, 2),
                'tax_amount'      => round($taxTotal, 2),
                'discount_amount' => 0,
                'total_amount'    => $total,
                'payment_method'  => $data['payment_method'],
                'amount_tendered' => $tendered,
                'change_amount'   => $change,
                'mpesa_ref'       => $data['mpesa_ref'] ?? null,
                'notes'           => $data['notes'] ?? null,
                'status'          => 'completed',
            ]);

            foreach ($lineItems as $line) {
                $sale->items()->create($line);
            }

            return ApiResponse::created($sale->load('items'), 'Sale completed');
        });
    }

    // ── Void a sale ───────────────────────────────────────────────────────────
    public function void(Request $request, int $id): JsonResponse
    {
        $sale = PosTransaction::findOrFail($id);

        if ($sale->status === 'voided') {
            return ApiResponse::error('Sale is already voided', 409);
        }

        $data = $request->validate([
            'void_reason' => 'required|string|max:200',
        ]);

        $sale->update([
            'status'      => 'voided',
            'voided_by'   => $request->user()?->user_id,
            'voided_at'   => now(),
            'void_reason' => $data['void_reason'],
        ]);

        return ApiResponse::updated($sale, 'Sale voided');
    }
}
