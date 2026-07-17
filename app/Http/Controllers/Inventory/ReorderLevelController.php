<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Item;
use App\Models\InventoryLocation;
use App\Models\ItemReorderLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReorderLevelController extends Controller
{
    /**
     * Return all locations with their reorder level for a given item.
     * Creates default 0-level records for locations that don't have one yet.
     */
    public function index(string $stockId): JsonResponse
    {
        Item::findOrFail($stockId);

        $locations = InventoryLocation::where('inactive', false)->orderBy('code')->get();

        $levels = [];
        foreach ($locations as $loc) {
            $level = ItemReorderLevel::firstOrCreate(
                ['stock_id' => $stockId, 'location_id' => $loc->id],
                ['reorder_level' => 0]
            );
            $levels[] = [
                'id'               => $level->id,
                'location_id'      => $loc->id,
                'location_name'    => $loc->name,
                'group_name'       => $loc->type ?? 'Locations',
                'reorder_level'    => $level->reorder_level,
                'quantity_on_hand' => 0, // placeholder until stock ledger is implemented
            ];
        }

        return ApiResponse::success($levels, 'Reorder levels retrieved');
    }

    public function update(Request $request, string $stockId, int $levelId): JsonResponse
    {
        $level = ItemReorderLevel::where("stock_id", $stockId)->findOrFail($levelId);
        $validated = $request->validate([
            'reorder_level' => 'required|numeric|min:0',
        ]);
        $level->update($validated);
        return ApiResponse::updated($level->fresh(), 'Reorder level updated');
    }
}
