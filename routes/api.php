<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PassportAuthController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Auth\RolePermissionController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Inventory\InventoryLocationController;
use App\Http\Controllers\Inventory\InventoryMovementReportController;
use App\Http\Controllers\Inventory\ItemCategoryController;
use App\Http\Controllers\MilkCollection\MilkCollectionController;
use App\Http\Controllers\Sales\DimensionPerformanceController;
use App\Http\Controllers\Sales\SalesOverviewController;
use App\Http\Controllers\Setup\DisplayController;
use App\Http\Controllers\Setup\RoleController;
use App\Http\Controllers\Setup\SystemDiagnosticsController;
use App\Http\Controllers\Setup\UserController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * DigiDash API — digidash auth + kirima read-only analytics.
 *
 * Write/CRUD routes that targeted digidash ERP tables have been retired.
 */

Route::get('health', function () {
    try {
        DB::connection()->getPdo();
        $appDb = 'ok';
    } catch (\Throwable $e) {
        $appDb = 'error: '.$e->getMessage();
    }

    try {
        DB::connection('kirima')->getPdo();
        $kirimaDb = 'ok';
    } catch (\Throwable $e) {
        $kirimaDb = 'error: '.$e->getMessage();
    }

    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'env' => config('app.env'),
        'db' => $appDb,
        'kirima' => $kirimaDb,
        'time' => now()->toDateTimeString(),
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

Route::prefix('oauth')->group(function () {
    Route::post('issue-token', [PassportAuthController::class, 'issueToken'])->name('passport.issue-token');
});

Route::middleware('auth:api')->group(function () {
    Route::prefix('oauth')->group(function () {
        Route::get('profile', [PassportAuthController::class, 'me']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('password', [ProfileController::class, 'changePassword']);
        Route::put('preferences', [ProfileController::class, 'preferences']);
        Route::get('tokens', [PassportAuthController::class, 'tokens']);
        Route::post('tokens/personal', [PassportAuthController::class, 'createPersonalToken']);
        Route::post('tokens/revoke', [PassportAuthController::class, 'revokeToken']);
        Route::post('refresh-token', [PassportAuthController::class, 'refresh']);
        Route::post('logout', [PassportAuthController::class, 'logout']);
    });

    Route::prefix('roles')->middleware('permission:view-roles')->group(function () {
        Route::get('/', [RolePermissionController::class, 'getAllRoles']);
        Route::get('{roleName}/permissions', [RolePermissionController::class, 'getRoleWithPermissions']);
        Route::post('/', [RolePermissionController::class, 'createRole'])->middleware('permission:create-roles');
        Route::delete('{roleName}', [RolePermissionController::class, 'deleteRole'])->middleware('permission:delete-roles');
    });

    Route::prefix('permissions')->middleware('permission:view-permissions')->group(function () {
        Route::get('/', [RolePermissionController::class, 'getAllPermissions']);
        Route::post('/', [RolePermissionController::class, 'createPermission'])->middleware('permission:create-permissions');
        Route::delete('{permissionName}', [RolePermissionController::class, 'deletePermission'])->middleware('permission:delete-permissions');
    });

    Route::post('roles/permissions/assign', [RolePermissionController::class, 'assignPermissionToRole'])
        ->middleware('permission:assign-roles');

    // ── Dashboard (kirima reads) ─────────────────────────────────────────────
    Route::prefix('dashboard')->group(function () {
        Route::get('summary', [DashboardController::class, 'summary']);
        Route::get('overview', [DashboardController::class, 'overview']);
        Route::get('category-items', [DashboardController::class, 'categoryItems']);
        Route::get('stores', [DashboardController::class, 'stores']);
        Route::get('high-risk-farmer', [DashboardController::class, 'getHighRiskFarmer']);
        Route::get('grader-variance', [DashboardController::class, 'getGraderHighestVariance']);
        Route::get('milk-chain', [DashboardController::class, 'milkChain']);
    });

    // ── Milk Collection (kirima reads) ───────────────────────────────────────
    Route::prefix('milk-collection')->group(function () {
        Route::get('chart', [MilkCollectionController::class, 'chart']);
        Route::get('count', [MilkCollectionController::class, 'count']);
        Route::get('records', [MilkCollectionController::class, 'records']);
        Route::get('storerevenue', [MilkCollectionController::class, 'storerevenue']);
        Route::get('farmercollection', [MilkCollectionController::class, 'farmercollection']);
        Route::get('farmercollection-export', [MilkCollectionController::class, 'farmercollectionExport']);
        Route::get('farmercollection-kpis', [MilkCollectionController::class, 'farmercollectionKpis']);
        Route::get('farmercollection-anomalies', [MilkCollectionController::class, 'farmercollectionWithAnomalies']);
        Route::get('storetransaction', [MilkCollectionController::class, 'getTransactionsPerItem']);
        Route::get('gradercollections', [MilkCollectionController::class, 'gradercollection']);
        Route::get('graderchartdata', [MilkCollectionController::class, 'graderchartdata']);
        Route::get('absent-farmers', [MilkCollectionController::class, 'absentFarmers']);
    });

    // ── Setup (digidash auth admin only) ─────────────────────────────────────
    Route::prefix('setup')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{id}', [UserController::class, 'update']);
        Route::delete('users/{id}', [UserController::class, 'destroy']);

        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::delete('roles/{name}', [RoleController::class, 'destroy']);
        Route::post('roles/{name}/sync', [RoleController::class, 'sync']);
        Route::get('permissions', [RoleController::class, 'allPermissions']);

        Route::get('display', [DisplayController::class, 'show']);
        Route::post('display', [DisplayController::class, 'update']);

        Route::get('system-diagnostics', [SystemDiagnosticsController::class, 'index']);
    });

    // ── Inventory lookups / reports (kirima reads) ───────────────────────────
    Route::prefix('inventory')->group(function () {
        Route::get('movement-report', [InventoryMovementReportController::class, 'index']);
        Route::get('milk-by-location', [InventoryMovementReportController::class, 'milkByLocation']);
        Route::get('item-categories', [ItemCategoryController::class, 'index']);
        Route::get('locations', [InventoryLocationController::class, 'index']);
    });

    // ── Sales analytics (kirima reads) ───────────────────────────────────────
    Route::prefix('sales')->group(function () {
        Route::get('overview', [SalesOverviewController::class, 'overview']);
        Route::get('dimensions/summary', [DimensionPerformanceController::class, 'summary']);
        Route::get('dimensions/daily', [DimensionPerformanceController::class, 'daily']);
        Route::get('dimensions/customers', [DimensionPerformanceController::class, 'customers']);
        Route::get('dimensions/{id}/items', [DimensionPerformanceController::class, 'items']);
        Route::get('dimensions/{id}/transactions', [DimensionPerformanceController::class, 'transactions']);
        Route::get('dimensions/{id}/payments', [DimensionPerformanceController::class, 'payments']);
    });
});
