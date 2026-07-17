<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RolePermissionController;
use App\Http\Controllers\Auth\PassportAuthController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Setup\CompanyController;
use App\Http\Controllers\Setup\UserController;
use App\Http\Controllers\Setup\RoleController;
use App\Http\Controllers\Setup\DisplayController;
use App\Http\Controllers\Setup\TransactionRefController;
use App\Http\Controllers\Setup\GlSettingController;
use App\Http\Controllers\Setup\TaxTypeController;
use App\Http\Controllers\Setup\TaxGroupController;
use App\Http\Controllers\Setup\ItemTaxTypeController;
use App\Http\Controllers\Setup\WithholdingTaxController;
use App\Http\Controllers\Setup\FiscalYearController;
use App\Http\Controllers\Setup\PrintingProfileController;
use App\Http\Controllers\Banking\ChartOfAccountsController;
use App\Http\Controllers\Banking\GlAccountClassController;
use App\Http\Controllers\Banking\GlAccountGroupController;
use App\Http\Controllers\Setup\PaymentTermController;
use App\Http\Controllers\Setup\FarmerPaymentTermController;
use App\Http\Controllers\Setup\ShippingCompanyController;
use App\Http\Controllers\Setup\PosSettingController;
use App\Http\Controllers\Setup\PrinterLocationController;
use App\Http\Controllers\Setup\ContactCategoryController;
use App\Http\Controllers\Setup\VoidTransactionController;
use App\Http\Controllers\Setup\ViewTransactionController;
use App\Http\Controllers\Setup\AttachDocumentController;
use App\Http\Controllers\Setup\BackupController;
use App\Http\Controllers\Setup\CompanyDatabaseController;
use App\Http\Controllers\Setup\SystemDiagnosticsController;
use App\Http\Controllers\Setup\DimensionController;
use App\Http\Controllers\Inventory\ItemCategoryController;
use App\Http\Controllers\Inventory\ItemController;
use App\Http\Controllers\Inventory\ItemSalesPriceController;
use App\Http\Controllers\Inventory\ItemPurchasePriceController;
use App\Http\Controllers\Inventory\ItemSubcategoryController;
use App\Http\Controllers\Inventory\UnitOfMeasureController;
use App\Http\Controllers\Inventory\InventoryLocationController;
use App\Http\Controllers\Inventory\StoreAllocationController;
use App\Http\Controllers\Inventory\SalesKitController;
use App\Http\Controllers\Inventory\ItemConversionController;
use App\Http\Controllers\Inventory\PackagingTypeController;
use App\Http\Controllers\Inventory\PackagingQuantityController;
use App\Http\Controllers\Inventory\ReorderLevelController;
use App\Http\Controllers\Sales\SalesTypeController;
use App\Http\Controllers\Sales\SalesAreaController;
use App\Http\Controllers\Sales\SalesPersonController;
use App\Http\Controllers\Sales\SalesGroupController;
use App\Http\Controllers\Sales\CreditNoteReasonController;
use App\Http\Controllers\Sales\CreditStatusController;
use App\Http\Controllers\Sales\PosController;
use App\Http\Controllers\Sales\DimensionPerformanceController;
use App\Http\Controllers\Inventory\InventoryTransferController;
use App\Http\Controllers\Inventory\InventoryAdjustmentController;
use App\Http\Controllers\Inventory\StockRequisitionController;
use App\Http\Controllers\Inventory\ConsumableIssueController;
use App\Http\Controllers\Inventory\StockTakeController;
use App\Http\Controllers\Inventory\PackagingTransferController;
use App\Http\Controllers\Inventory\PackagingReceiveController;
use App\Http\Controllers\Inventory\InventoryKpiController;
use App\Http\Controllers\Inventory\StockMovementInquiryController;
use App\Http\Controllers\Inventory\InventoryMovementReportController;
use App\Http\Controllers\Purchases\SupplierController;
use App\Http\Controllers\Purchases\PurchaseRequisitionController;
use App\Http\Controllers\Purchases\PurchaseOrderController;
use App\Http\Controllers\Purchases\PurchaseQuotationController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\MilkCollection\MilkCollectionController;

/**
 * Authentication Routes
 * 
 * Public routes for authentication (login, register)
 * Protected routes for user management and role/permission assignment
 * OAuth2 routes for external system API access via Passport
 */
// ==========================================
// ── Health check (no auth required) ──────────────────────────────────────────
Route::get('health', function () {
    try {
        DB::connection()->getPdo();
        $db = 'ok';
    } catch (\Throwable $e) {
        $db = 'error: ' . $e->getMessage();
    }
    return response()->json([
        'status'  => 'ok',
        'app'     => config('app.name'),
        'env'     => config('app.env'),
        'db'      => $db,
        'time'    => now()->toDateTimeString(),
    ]);
});

// LEGACY SANCTUM AUTHENTICATION (Deprecated)
// Use Passport OAuth2 instead for new systems
// ==========================================
Route::prefix('auth')->group(function () {
    Route::post('login',           [AuthController::class, 'login']);
    Route::post('register',        [AuthController::class, 'register']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [AuthController::class, 'resetPassword']);
});

// ==========================================
// OAUTH2 / PASSPORT AUTHENTICATION
// For external systems and API integrations
// ==========================================

// Passport OAuth2 Password Grant Flow
// External systems use this to obtain access tokens
Route::prefix('oauth')->group(function () {
    // Issue token endpoint - used by external systems
    // POST /api/oauth/issue-token
    // Body: { user_id, password, scope (optional) }
    Route::post('issue-token', [PassportAuthController::class, 'issueToken'])->name('passport.issue-token');
});

// Passport protected routes - requires valid OAuth2 token
Route::middleware('auth:api')->group(function () {
    Route::prefix('oauth')->group(function () {
        // Get / update current authenticated user
        Route::get('profile',     [PassportAuthController::class, 'me']);
        Route::put('profile',     [ProfileController::class, 'update']);
        Route::put('password',    [ProfileController::class, 'changePassword']);
        Route::put('preferences', [ProfileController::class, 'preferences']);
        // Token management
        Route::get('tokens', [PassportAuthController::class, 'tokens']);
        Route::post('tokens/personal', [PassportAuthController::class, 'createPersonalToken']);
        Route::post('tokens/revoke', [PassportAuthController::class, 'revokeToken']);
        // Refresh access token
        Route::post('refresh-token', [PassportAuthController::class, 'refresh']);
        // Logout (revoke all tokens)
        Route::post('logout', [PassportAuthController::class, 'logout']);
    });

    // Role and Permission Management (requires specific permissions)
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

    // Assign permissions to roles
    Route::post('roles/permissions/assign', [RolePermissionController::class, 'assignPermissionToRole'])
        ->middleware('permission:assign-roles');

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::prefix('dashboard')->group(function () {
        Route::get('summary',           [DashboardController::class, 'summary']);
        Route::get('category-items',    [DashboardController::class, 'categoryItems']);
        Route::get('stores',            [DashboardController::class, 'stores']);
        Route::get('high-risk-farmer',    [DashboardController::class, 'getHighRiskFarmer']);
        Route::get('grader-variance',     [DashboardController::class, 'getGraderHighestVariance']);
        Route::get('milk-chain',          [DashboardController::class, 'milkChain']);
    });

    // ── Milk Collection ───────────────────────────────────────────────────────
    Route::prefix('milk-collection')->group(function () {
        Route::get('chart',   [MilkCollectionController::class, 'chart']);
        Route::get('count',   [MilkCollectionController::class, 'count']);
        Route::get('records', [MilkCollectionController::class, 'records']);
        Route::get('storerevenue', [MilkCollectionController::class, 'storerevenue']);
        Route::get('farmercollection', [MilkCollectionController::class, 'farmercollection']);
        Route::get('farmercollection-export', [MilkCollectionController::class, 'farmercollectionExport']);
        Route::get('farmercollection-kpis', [MilkCollectionController::class, 'farmercollectionKpis']);
        Route::get('farmercollection-anomalies', [MilkCollectionController::class, 'farmercollectionWithAnomalies']);
        Route::get('storetransaction', [MilkCollectionController::class, 'getTransactionsPerItem']);
        Route::get('gradercollections', [MilkCollectionController::class, 'gradercollection']);
        Route::get('graderchartdata',   [MilkCollectionController::class, 'graderchartdata']);
        Route::get('absent-farmers',    [MilkCollectionController::class, 'absentFarmers']);
    });

    // ── Setup ─────────────────────────────────────────────────────────────────
    Route::prefix('setup')->group(function () {
        Route::get('company',  [CompanyController::class, 'show']);
        Route::post('company', [CompanyController::class, 'update']);

        Route::get('users',         [UserController::class, 'index']);
        Route::post('users',        [UserController::class, 'store']);
        Route::put('users/{id}',    [UserController::class, 'update']);
        Route::delete('users/{id}', [UserController::class, 'destroy']);

        Route::get('roles',                    [RoleController::class, 'index']);
        Route::post('roles',                   [RoleController::class, 'store']);
        Route::delete('roles/{name}',          [RoleController::class, 'destroy']);
        Route::post('roles/{name}/sync',       [RoleController::class, 'sync']);
        Route::get('permissions',              [RoleController::class, 'allPermissions']);

        Route::get('display',  [DisplayController::class, 'show']);
        Route::post('display', [DisplayController::class, 'update']);

        Route::get('gl-settings',  [GlSettingController::class, 'show']);
        Route::post('gl-settings', [GlSettingController::class, 'update']);

        Route::get('transaction-refs',         [TransactionRefController::class, 'index']);
        Route::post('transaction-refs',        [TransactionRefController::class, 'store']);
        Route::put('transaction-refs/{id}',    [TransactionRefController::class, 'update']);
        Route::delete('transaction-refs/{id}', [TransactionRefController::class, 'destroy']);

        Route::get('tax-types',         [TaxTypeController::class, 'index']);
        Route::post('tax-types',        [TaxTypeController::class, 'store']);
        Route::put('tax-types/{id}',    [TaxTypeController::class, 'update']);
        Route::delete('tax-types/{id}', [TaxTypeController::class, 'destroy']);

        Route::get('tax-groups',         [TaxGroupController::class, 'index']);
        Route::post('tax-groups',        [TaxGroupController::class, 'store']);
        Route::put('tax-groups/{id}',    [TaxGroupController::class, 'update']);
        Route::delete('tax-groups/{id}', [TaxGroupController::class, 'destroy']);

        Route::get('item-tax-types',         [ItemTaxTypeController::class, 'index']);
        Route::post('item-tax-types',        [ItemTaxTypeController::class, 'store']);
        Route::put('item-tax-types/{id}',    [ItemTaxTypeController::class, 'update']);
        Route::delete('item-tax-types/{id}', [ItemTaxTypeController::class, 'destroy']);

        Route::get('withholding-taxes',         [WithholdingTaxController::class, 'index']);
        Route::post('withholding-taxes',        [WithholdingTaxController::class, 'store']);
        Route::put('withholding-taxes/{id}',    [WithholdingTaxController::class, 'update']);
        Route::delete('withholding-taxes/{id}', [WithholdingTaxController::class, 'destroy']);

        Route::get('fiscal-years',         [FiscalYearController::class, 'index']);
        Route::post('fiscal-years',        [FiscalYearController::class, 'store']);
        Route::put('fiscal-years/{id}',    [FiscalYearController::class, 'update']);
        Route::delete('fiscal-years/{id}', [FiscalYearController::class, 'destroy']);

        Route::get('printing-profiles',         [PrintingProfileController::class, 'index']);
        Route::get('printing-profiles/{id}',    [PrintingProfileController::class, 'show']);
        Route::post('printing-profiles',        [PrintingProfileController::class, 'store']);
        Route::put('printing-profiles/{id}',    [PrintingProfileController::class, 'update']);
        Route::delete('printing-profiles/{id}', [PrintingProfileController::class, 'destroy']);

        // ── Payment Terms ──────────────────────────────────────────────────────
        Route::get('payment-terms',         [PaymentTermController::class, 'index']);
        Route::post('payment-terms',        [PaymentTermController::class, 'store']);
        Route::put('payment-terms/{id}',    [PaymentTermController::class, 'update']);
        Route::delete('payment-terms/{id}', [PaymentTermController::class, 'destroy']);

        // ── Farmer Payment Terms ───────────────────────────────────────────────
        Route::get('farmer-payment-terms',         [FarmerPaymentTermController::class, 'index']);
        Route::post('farmer-payment-terms',        [FarmerPaymentTermController::class, 'store']);
        Route::put('farmer-payment-terms/{id}',    [FarmerPaymentTermController::class, 'update']);
        Route::delete('farmer-payment-terms/{id}', [FarmerPaymentTermController::class, 'destroy']);

        // ── Shipping Companies ─────────────────────────────────────────────────
        Route::get('shipping-companies',         [ShippingCompanyController::class, 'index']);
        Route::post('shipping-companies',        [ShippingCompanyController::class, 'store']);
        Route::put('shipping-companies/{id}',    [ShippingCompanyController::class, 'update']);
        Route::delete('shipping-companies/{id}', [ShippingCompanyController::class, 'destroy']);

        // ── POS Settings ───────────────────────────────────────────────────────
        Route::get('pos-settings',         [PosSettingController::class, 'index']);
        Route::post('pos-settings',        [PosSettingController::class, 'store']);
        Route::put('pos-settings/{id}',    [PosSettingController::class, 'update']);
        Route::delete('pos-settings/{id}', [PosSettingController::class, 'destroy']);

        // ── Printer Locations ──────────────────────────────────────────────────
        Route::get('printer-locations',         [PrinterLocationController::class, 'index']);
        Route::post('printer-locations',        [PrinterLocationController::class, 'store']);
        Route::put('printer-locations/{id}',    [PrinterLocationController::class, 'update']);
        Route::delete('printer-locations/{id}', [PrinterLocationController::class, 'destroy']);

        // ── Contact Categories ─────────────────────────────────────────────────
        Route::get('contact-categories',         [ContactCategoryController::class, 'index']);
        Route::post('contact-categories',        [ContactCategoryController::class, 'store']);
        Route::put('contact-categories/{id}',    [ContactCategoryController::class, 'update']);
        Route::delete('contact-categories/{id}', [ContactCategoryController::class, 'destroy']);

        // ── Void Transaction ───────────────────────────────────────────────────
        Route::get('void-transaction/search',  [VoidTransactionController::class, 'search']);
        Route::post('void-transaction/void',   [VoidTransactionController::class, 'void']);

        // ── View Transactions ──────────────────────────────────────────────────
        Route::get('view-transactions/search', [ViewTransactionController::class, 'search']);

        // ── Attach Documents ───────────────────────────────────────────────────
        Route::get('attach-documents',         [AttachDocumentController::class, 'index']);
        Route::post('attach-documents',        [AttachDocumentController::class, 'store']);
        Route::delete('attach-documents/{id}', [AttachDocumentController::class, 'destroy']);

        // ── Backup & Restore ───────────────────────────────────────────────────
        Route::get('backup',                    [BackupController::class, 'index']);
        Route::post('backup',                   [BackupController::class, 'create']);
        Route::get('backup/{filename}/download',[BackupController::class, 'download'])->where('filename', '.+');
        Route::post('backup/restore',           [BackupController::class, 'restore']);
        Route::delete('backup/{filename}',      [BackupController::class, 'destroy'])->where('filename', '.+');

        // ── Company Databases ──────────────────────────────────────────────────
        Route::get('company-databases',         [CompanyDatabaseController::class, 'index']);
        Route::post('company-databases',        [CompanyDatabaseController::class, 'store']);
        Route::put('company-databases/{id}',    [CompanyDatabaseController::class, 'update']);
        Route::delete('company-databases/{id}', [CompanyDatabaseController::class, 'destroy']);

        // ── System Diagnostics ─────────────────────────────────────────────────
        Route::get('system-diagnostics',        [SystemDiagnosticsController::class, 'index']);

        // ── Dimensions ──────────────────────────────────────────────────────────
        Route::get('dimensions/next-reference',  [DimensionController::class, 'nextReference']);
        Route::get('dimensions',                 [DimensionController::class, 'index']);
        Route::post('dimensions',                [DimensionController::class, 'store']);
        Route::put('dimensions/{id}',            [DimensionController::class, 'update']);
        Route::delete('dimensions/{id}',         [DimensionController::class, 'destroy']);
    });

    // ── Banking / GL ───────────────────────────────────────────────────────────
    Route::prefix('banking')->group(function () {
        Route::get('gl-accounts',          [ChartOfAccountsController::class, 'index']);
        Route::post('gl-accounts',         [ChartOfAccountsController::class, 'store']);
        Route::put('gl-accounts/{id}',     [ChartOfAccountsController::class, 'update']);
        Route::delete('gl-accounts/{id}',  [ChartOfAccountsController::class, 'destroy']);

        Route::get('gl-account-groups',    [ChartOfAccountsController::class, 'groups']);
        Route::post('gl-account-groups',   [ChartOfAccountsController::class, 'storeGroup']);

        // GL Classes
        Route::get('gl-classes',           [GlAccountClassController::class, 'index']);
        Route::post('gl-classes',          [GlAccountClassController::class, 'store']);
        Route::put('gl-classes/{id}',      [GlAccountClassController::class, 'update']);
        Route::delete('gl-classes/{id}',   [GlAccountClassController::class, 'destroy']);

        // GL Groups (full CRUD with class + parent)
        Route::get('gl-groups',            [GlAccountGroupController::class, 'index']);
        Route::post('gl-groups',           [GlAccountGroupController::class, 'store']);
        Route::put('gl-groups/{id}',       [GlAccountGroupController::class, 'update']);
        Route::delete('gl-groups/{id}',    [GlAccountGroupController::class, 'destroy']);
    });

    // ── Inventory ──────────────────────────────────────────────────────────────
    Route::prefix('inventory')->group(function () {
        Route::get('kpis',       [InventoryKpiController::class,         'index']);
        Route::get('movements',        [StockMovementInquiryController::class,    'index']);
        Route::get('movement-report',   [InventoryMovementReportController::class, 'index']);
        Route::get('milk-by-location',  [InventoryMovementReportController::class, 'milkByLocation']);

        // Item Categories
        Route::get('item-categories',          [ItemCategoryController::class, 'index']);
        Route::post('item-categories',         [ItemCategoryController::class, 'store']);
        Route::put('item-categories/{id}',     [ItemCategoryController::class, 'update']);
        Route::delete('item-categories/{id}',  [ItemCategoryController::class, 'destroy']);

        // Items
        Route::get('items',          [ItemController::class, 'index']);
        Route::get('items/{id}',     [ItemController::class, 'show']);
        Route::post('items',         [ItemController::class, 'store']);
        Route::post('items/{id}',    [ItemController::class, 'update']); // POST for multipart (image upload)
        Route::delete('items/{id}',  [ItemController::class, 'destroy']);

        // Item Sales Prices (nested)
        Route::get('items/{id}/sales-prices',              [ItemSalesPriceController::class, 'index']);
        Route::post('items/{id}/sales-prices',             [ItemSalesPriceController::class, 'store']);
        Route::put('items/{id}/sales-prices/{priceId}',    [ItemSalesPriceController::class, 'update']);
        Route::delete('items/{id}/sales-prices/{priceId}', [ItemSalesPriceController::class, 'destroy']);

        // Item Purchase Prices (nested)
        Route::get('items/{id}/purchase-prices',              [ItemPurchasePriceController::class, 'index']);
        Route::post('items/{id}/purchase-prices',             [ItemPurchasePriceController::class, 'store']);
        Route::put('items/{id}/purchase-prices/{priceId}',    [ItemPurchasePriceController::class, 'update']);
        Route::delete('items/{id}/purchase-prices/{priceId}', [ItemPurchasePriceController::class, 'destroy']);

        // Item Conversion (nested under items)
        Route::get('items/{itemId}/conversion-items',              [ItemConversionController::class, 'index']);
        Route::post('items/{itemId}/conversion-items',             [ItemConversionController::class, 'store']);
        Route::delete('items/{itemId}/conversion-items/{id}',      [ItemConversionController::class, 'destroy']);

        // Reorder Levels (nested under items)
        Route::get('items/{itemId}/reorder-levels',            [ReorderLevelController::class, 'index']);
        Route::put('items/{itemId}/reorder-levels/{levelId}',  [ReorderLevelController::class, 'update']);

        // Item Subcategories
        Route::get('item-subcategories',          [ItemSubcategoryController::class, 'index']);
        Route::post('item-subcategories',         [ItemSubcategoryController::class, 'store']);
        Route::put('item-subcategories/{id}',     [ItemSubcategoryController::class, 'update']);
        Route::delete('item-subcategories/{id}',  [ItemSubcategoryController::class, 'destroy']);

        // Units of Measure
        Route::get('units-of-measure',          [UnitOfMeasureController::class, 'index']);
        Route::post('units-of-measure',         [UnitOfMeasureController::class, 'store']);
        Route::put('units-of-measure/{id}',     [UnitOfMeasureController::class, 'update']);
        Route::delete('units-of-measure/{id}',  [UnitOfMeasureController::class, 'destroy']);

        // Inventory Locations
        Route::get('locations',          [InventoryLocationController::class, 'index']);
        Route::post('locations',         [InventoryLocationController::class, 'store']);
        Route::put('locations/{id}',     [InventoryLocationController::class, 'update']);
        Route::delete('locations/{id}',  [InventoryLocationController::class, 'destroy']);

        // Store Allocations
        Route::get('store-allocations',          [StoreAllocationController::class, 'index']);
        Route::post('store-allocations',         [StoreAllocationController::class, 'store']);
        Route::delete('store-allocations/{id}',  [StoreAllocationController::class, 'destroy']);

        // Sales Kits
        Route::get('sales-kits',          [SalesKitController::class, 'index']);
        Route::post('sales-kits',         [SalesKitController::class, 'store']);
        Route::put('sales-kits/{id}',     [SalesKitController::class, 'update']);
        Route::delete('sales-kits/{id}',  [SalesKitController::class, 'destroy']);
        Route::get('sales-kits/{kitId}/items',               [SalesKitController::class, 'kitItems']);
        Route::post('sales-kits/{kitId}/items',              [SalesKitController::class, 'addKitItem']);
        Route::delete('sales-kits/{kitId}/items/{itemId}',   [SalesKitController::class, 'removeKitItem']);

        // Packaging Types
        Route::get('packaging-types',          [PackagingTypeController::class, 'index']);
        Route::post('packaging-types',         [PackagingTypeController::class, 'store']);
        Route::put('packaging-types/{id}',     [PackagingTypeController::class, 'update']);
        Route::delete('packaging-types/{id}',  [PackagingTypeController::class, 'destroy']);

        // Packaging Quantities
        Route::get('packaging-quantities/summary',   [PackagingQuantityController::class, 'summary']);
        Route::get('packaging-quantities',            [PackagingQuantityController::class, 'index']);
        Route::post('packaging-quantities',           [PackagingQuantityController::class, 'store']);
        Route::delete('packaging-quantities/{id}',   [PackagingQuantityController::class, 'destroy']);

        // Inventory Location Transfers
        Route::get('transfers',                      [InventoryTransferController::class, 'index']);
        Route::post('transfers',                     [InventoryTransferController::class, 'store']);
        Route::get('transfers/{id}',                 [InventoryTransferController::class, 'show']);
        Route::put('transfers/{id}',                 [InventoryTransferController::class, 'update']);
        Route::post('transfers/{id}/submit',         [InventoryTransferController::class, 'submit']);
        Route::post('transfers/{id}/approve',        [InventoryTransferController::class, 'approve']);
        Route::post('transfers/{id}/reject',         [InventoryTransferController::class, 'reject']);
        Route::delete('transfers/{id}',              [InventoryTransferController::class, 'destroy']);

        // Inventory Adjustments
        Route::get('adjustments',                    [InventoryAdjustmentController::class, 'index']);
        Route::post('adjustments',                   [InventoryAdjustmentController::class, 'store']);
        Route::get('adjustments/{id}',               [InventoryAdjustmentController::class, 'show']);
        Route::put('adjustments/{id}',               [InventoryAdjustmentController::class, 'update']);
        Route::post('adjustments/{id}/process',      [InventoryAdjustmentController::class, 'process']);
        Route::delete('adjustments/{id}',            [InventoryAdjustmentController::class, 'destroy']);

        // Stock Requisitions
        Route::get('requisitions',                   [StockRequisitionController::class, 'index']);
        Route::post('requisitions',                  [StockRequisitionController::class, 'store']);
        Route::get('requisitions/{id}',              [StockRequisitionController::class, 'show']);
        Route::put('requisitions/{id}',              [StockRequisitionController::class, 'update']);
        Route::post('requisitions/{id}/submit',      [StockRequisitionController::class, 'submit']);
        Route::post('requisitions/{id}/approve',     [StockRequisitionController::class, 'approve']);
        Route::post('requisitions/{id}/reject',      [StockRequisitionController::class, 'reject']);
        Route::post('requisitions/{id}/dispatch',    [StockRequisitionController::class, 'dispatch']);
        Route::delete('requisitions/{id}',           [StockRequisitionController::class, 'destroy']);

        // Consumable Issues
        Route::get('consumable-issues',              [ConsumableIssueController::class, 'index']);
        Route::post('consumable-issues',             [ConsumableIssueController::class, 'store']);
        Route::get('consumable-issues/{id}',         [ConsumableIssueController::class, 'show']);
        Route::put('consumable-issues/{id}',         [ConsumableIssueController::class, 'update']);
        Route::post('consumable-issues/{id}/submit', [ConsumableIssueController::class, 'submit']);
        Route::post('consumable-issues/{id}/approve',[ConsumableIssueController::class, 'approve']);
        Route::post('consumable-issues/{id}/reject', [ConsumableIssueController::class, 'reject']);
        Route::delete('consumable-issues/{id}',      [ConsumableIssueController::class, 'destroy']);

        // Stock Takes
        Route::get('stock-takes',                    [StockTakeController::class, 'index']);
        Route::post('stock-takes',                   [StockTakeController::class, 'store']);
        Route::get('stock-takes/{id}',               [StockTakeController::class, 'show']);
        Route::post('stock-takes/{id}/submit',        [StockTakeController::class, 'submit']);
        Route::post('stock-takes/{id}/approve',       [StockTakeController::class, 'approve']);
        Route::delete('stock-takes/{id}',             [StockTakeController::class, 'destroy']);

        // Packaging Transfers
        Route::get('packaging-transfers',             [PackagingTransferController::class, 'index']);
        Route::post('packaging-transfers',            [PackagingTransferController::class, 'store']);
        Route::get('packaging-transfers/{id}',        [PackagingTransferController::class, 'show']);
        Route::post('packaging-transfers/{id}/process', [PackagingTransferController::class, 'process']);
        Route::delete('packaging-transfers/{id}',     [PackagingTransferController::class, 'destroy']);

        // Packaging Receives
        Route::get('packaging-receives',              [PackagingReceiveController::class, 'index']);
        Route::post('packaging-receives',             [PackagingReceiveController::class, 'store']);
        Route::get('packaging-receives/{id}',         [PackagingReceiveController::class, 'show']);
        Route::delete('packaging-receives/{id}',      [PackagingReceiveController::class, 'destroy']);
    });

    // ── Sales Maintenance ────────────────────────────────────────────────────
    Route::prefix('sales')->group(function () {
        Route::get('dimensions/summary',              [DimensionPerformanceController::class, 'summary']);
        Route::get('dimensions/daily',               [DimensionPerformanceController::class, 'daily']);
        Route::get('dimensions/customers',           [DimensionPerformanceController::class, 'customers']);
        Route::get('dimensions/{id}/items',          [DimensionPerformanceController::class, 'items']);
        Route::get('dimensions/{id}/transactions',   [DimensionPerformanceController::class, 'transactions']);
        Route::get('dimensions/{id}/payments',       [DimensionPerformanceController::class, 'payments']);

        Route::get('types',          [SalesTypeController::class, 'index']);
        Route::post('types',         [SalesTypeController::class, 'store']);
        Route::put('types/{id}',     [SalesTypeController::class, 'update']);
        Route::delete('types/{id}',  [SalesTypeController::class, 'destroy']);

        Route::get('areas',          [SalesAreaController::class, 'index']);
        Route::post('areas',         [SalesAreaController::class, 'store']);
        Route::put('areas/{id}',     [SalesAreaController::class, 'update']);
        Route::delete('areas/{id}',  [SalesAreaController::class, 'destroy']);

        Route::get('persons',          [SalesPersonController::class, 'index']);
        Route::post('persons',         [SalesPersonController::class, 'store']);
        Route::put('persons/{id}',     [SalesPersonController::class, 'update']);
        Route::delete('persons/{id}',  [SalesPersonController::class, 'destroy']);

        Route::get('groups',          [SalesGroupController::class, 'index']);
        Route::post('groups',         [SalesGroupController::class, 'store']);
        Route::put('groups/{id}',     [SalesGroupController::class, 'update']);
        Route::delete('groups/{id}',  [SalesGroupController::class, 'destroy']);

        Route::get('credit-note-reasons',          [CreditNoteReasonController::class, 'index']);
        Route::post('credit-note-reasons',         [CreditNoteReasonController::class, 'store']);
        Route::put('credit-note-reasons/{id}',     [CreditNoteReasonController::class, 'update']);
        Route::delete('credit-note-reasons/{id}',  [CreditNoteReasonController::class, 'destroy']);

        Route::get('credit-statuses',          [CreditStatusController::class, 'index']);
        Route::post('credit-statuses',         [CreditStatusController::class, 'store']);
        Route::put('credit-statuses/{id}',     [CreditStatusController::class, 'update']);
        Route::delete('credit-statuses/{id}',  [CreditStatusController::class, 'destroy']);

        // POS
        Route::get('pos/lookup',             [PosController::class, 'lookup']);
        Route::get('pos/search',             [PosController::class, 'search']);
        Route::get('pos/sales',              [PosController::class, 'index']);
        Route::post('pos/sales',             [PosController::class, 'store']);
        Route::get('pos/sales/{id}',         [PosController::class, 'show']);
        Route::post('pos/sales/{id}/void',   [PosController::class, 'void']);
    });

    // ── Purchases ─────────────────────────────────────────────────────────────
    Route::prefix('purchases')->group(function () {
        // Suppliers
        Route::get('suppliers',          [SupplierController::class, 'index']);
        Route::post('suppliers',         [SupplierController::class, 'store']);
        Route::get('suppliers/{id}',     [SupplierController::class, 'show']);
        Route::put('suppliers/{id}',     [SupplierController::class, 'update']);
        Route::delete('suppliers/{id}',  [SupplierController::class, 'destroy']);

        // Purchase Requisitions
        Route::get('requisitions',                              [PurchaseRequisitionController::class, 'index']);
        Route::post('requisitions',                             [PurchaseRequisitionController::class, 'store']);
        Route::get('requisitions/{id}',                         [PurchaseRequisitionController::class, 'show']);
        Route::put('requisitions/{id}',                         [PurchaseRequisitionController::class, 'update']);
        Route::delete('requisitions/{id}',                      [PurchaseRequisitionController::class, 'destroy']);
        Route::post('requisitions/{id}/submit',                 [PurchaseRequisitionController::class, 'submit']);
        Route::post('requisitions/{id}/hod-approve',            [PurchaseRequisitionController::class, 'hodApprove']);
        Route::post('requisitions/{id}/finance-approve',        [PurchaseRequisitionController::class, 'financeApprove']);
        Route::post('requisitions/{id}/ceo-approve',            [PurchaseRequisitionController::class, 'ceoApprove']);
        Route::post('requisitions/{id}/reject',                 [PurchaseRequisitionController::class, 'reject']);

        // Purchase Quotations (RFQ)
        Route::get('quotations',                                    [PurchaseQuotationController::class, 'index']);
        Route::post('quotations',                                   [PurchaseQuotationController::class, 'store']);
        Route::get('quotations/{id}',                               [PurchaseQuotationController::class, 'show']);
        Route::delete('quotations/{id}',                            [PurchaseQuotationController::class, 'destroy']);
        Route::post('quotations/{id}/dispatch',                     [PurchaseQuotationController::class, 'dispatch']);
        Route::post('quotations/{id}/receive-response',             [PurchaseQuotationController::class, 'receiveResponse']);
        Route::post('quotations/{id}/rank',                         [PurchaseQuotationController::class, 'rank']);
        Route::post('quotations/{id}/convert-to-po',                [PurchaseQuotationController::class, 'convertToPo']);

        // Purchase Orders (type: po | grn | invoice via ?type=)
        Route::get('orders',                                    [PurchaseOrderController::class, 'index']);
        Route::post('orders',                                   [PurchaseOrderController::class, 'store']);
        Route::get('orders/{id}',                               [PurchaseOrderController::class, 'show']);
        Route::put('orders/{id}',                               [PurchaseOrderController::class, 'update']);
        Route::delete('orders/{id}',                            [PurchaseOrderController::class, 'destroy']);
        Route::post('orders/{id}/submit',                       [PurchaseOrderController::class, 'submit']);
        Route::post('orders/{id}/hod-approve',                  [PurchaseOrderController::class, 'hodApprove']);
        Route::post('orders/{id}/finance-approve',              [PurchaseOrderController::class, 'financeApprove']);
        Route::post('orders/{id}/ceo-approve',                  [PurchaseOrderController::class, 'ceoApprove']);
        Route::post('orders/{id}/reject',                       [PurchaseOrderController::class, 'reject']);
    });
});
