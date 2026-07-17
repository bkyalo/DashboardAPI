<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Item::with('category')->orderBy('description');

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('stock_id', 'like', $s)
                  ->orWhere('description', 'like', $s);
            });
        }

        if ($request->filled('status')) {
            $query->where('inactive', $request->status);
        }

        return ApiResponse::success($query->get(), 'Items retrieved');
    }

    public function show(string $stockId): JsonResponse
    {
        $item = Item::with('category')->findOrFail($stockId);
        return ApiResponse::success($item, 'Item retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_id'            => 'required|string|max:20|unique:items,stock_id',
            'description'         => 'required|string|max:200',
            'long_description'    => 'nullable|string',
            'category_id'         => 'nullable|integer',
            'tax_type_id'         => 'nullable|integer',
            'units'               => 'nullable|string|max:20',
            'mb_flag'             => 'nullable|string|max:1',
            'supplier_id'         => 'nullable|string|max:100',
            'dimension_id'        => 'nullable|integer',
            'dimension2_id'       => 'nullable|integer',
            'sales_account'       => 'nullable|string|max:15',
            'cogs_account'        => 'nullable|string|max:15',
            'inventory_account'   => 'nullable|string|max:15',
            'adjustment_account'  => 'nullable|string|max:15',
            'wip_account'         => 'nullable|string|max:15',
            'purchase_cost'       => 'nullable|numeric|min:0',
            'material_cost'       => 'nullable|numeric|min:0',
            'labour_cost'         => 'nullable|numeric|min:0',
            'overhead_cost'       => 'nullable|numeric|min:0',
            'inactive'            => 'boolean',
            'no_sale'             => 'boolean',
            'no_purchase'         => 'boolean',
            'editable'            => 'boolean',
            'apply_batching'      => 'boolean',
            'ai_item'             => 'boolean',
            'treatment_item'      => 'boolean',
            'hs_code'             => 'nullable|string|max:100',
            'bar_code'            => 'nullable|string|max:100',
            'material_weight'     => 'nullable|numeric|min:0',
            'item_commission'     => 'nullable|numeric|min:0',
            'sell_in_pieces'      => 'nullable|integer|min:0',
            'sub_uoms'            => 'nullable|string|max:250',
            'depreciation_method' => 'nullable|string|max:1',
            'depreciation_rate'   => 'nullable|numeric|min:0',
            'depreciation_factor' => 'nullable|numeric|min:0',
            'depreciation_start'  => 'nullable|string|max:20',
            'depreciation_date'   => 'nullable|string|max:20',
            'fa_class_id'         => 'nullable|string|max:20',
            'image'               => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $validated['image_filename'] = $request->file('image')->store('item-images', 'public');
        }

        $item = Item::create($validated);
        return ApiResponse::created($item->load('category'), 'Item created');
    }

    public function update(Request $request, string $stockId): JsonResponse
    {
        $item = Item::findOrFail($stockId);

        $validated = $request->validate([
            'description'         => 'sometimes|string|max:200',
            'long_description'    => 'nullable|string',
            'category_id'         => 'nullable|integer',
            'tax_type_id'         => 'nullable|integer',
            'units'               => 'nullable|string|max:20',
            'mb_flag'             => 'nullable|string|max:1',
            'supplier_id'         => 'nullable|string|max:100',
            'dimension_id'        => 'nullable|integer',
            'dimension2_id'       => 'nullable|integer',
            'sales_account'       => 'nullable|string|max:15',
            'cogs_account'        => 'nullable|string|max:15',
            'inventory_account'   => 'nullable|string|max:15',
            'adjustment_account'  => 'nullable|string|max:15',
            'wip_account'         => 'nullable|string|max:15',
            'purchase_cost'       => 'nullable|numeric|min:0',
            'material_cost'       => 'nullable|numeric|min:0',
            'labour_cost'         => 'nullable|numeric|min:0',
            'overhead_cost'       => 'nullable|numeric|min:0',
            'inactive'            => 'sometimes|boolean',
            'no_sale'             => 'sometimes|boolean',
            'no_purchase'         => 'sometimes|boolean',
            'editable'            => 'sometimes|boolean',
            'apply_batching'      => 'sometimes|boolean',
            'ai_item'             => 'sometimes|boolean',
            'treatment_item'      => 'sometimes|boolean',
            'hs_code'             => 'nullable|string|max:100',
            'bar_code'            => 'nullable|string|max:100',
            'material_weight'     => 'nullable|numeric|min:0',
            'item_commission'     => 'nullable|numeric|min:0',
            'sell_in_pieces'      => 'nullable|integer|min:0',
            'sub_uoms'            => 'nullable|string|max:250',
            'depreciation_method' => 'nullable|string|max:1',
            'depreciation_rate'   => 'nullable|numeric|min:0',
            'depreciation_factor' => 'nullable|numeric|min:0',
            'depreciation_start'  => 'nullable|string|max:20',
            'depreciation_date'   => 'nullable|string|max:20',
            'fa_class_id'         => 'nullable|string|max:20',
            'image'               => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            if ($item->image_filename) {
                Storage::disk('public')->delete($item->image_filename);
            }
            $validated['image_filename'] = $request->file('image')->store('item-images', 'public');
        }

        $item->fill($validated)->save();
        return ApiResponse::updated($item->fresh()->load('category'), 'Item updated');
    }

    public function destroy(string $stockId): JsonResponse
    {
        $item = Item::findOrFail($stockId);
        $item->update(['inactive' => true]);
        return ApiResponse::deleted('Item deactivated');
    }
}
