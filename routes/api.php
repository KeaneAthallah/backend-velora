<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // auth
    Route::post('auth/register', [\App\Http\Controllers\Api\V1\AuthController::class, 'register']);
    Route::post('auth/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login']);
    Route::post('auth/forgot-password', [\App\Http\Controllers\Api\V1\AuthController::class, 'forgotPassword']);
    Route::post('auth/reset-password', [\App\Http\Controllers\Api\V1\AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [\App\Http\Controllers\Api\V1\MeController::class, 'show']);
        Route::post('auth/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
        Route::post('auth/change-password', [\App\Http\Controllers\Api\V1\AuthController::class, 'changePassword']);

        // businesses (no tenant context required for list/create)
        Route::get('businesses', [\App\Http\Controllers\Api\V1\BusinessController::class, 'index']);
        Route::post('businesses', [\App\Http\Controllers\Api\V1\BusinessController::class, 'store']);
        Route::get('businesses/{business}', [\App\Http\Controllers\Api\V1\BusinessController::class, 'show']);
        Route::put('businesses/{business}', [\App\Http\Controllers\Api\V1\BusinessController::class, 'update']);
        Route::delete('businesses/{business}', [\App\Http\Controllers\Api\V1\BusinessController::class, 'destroy']);

        // tenant-scoped
        Route::middleware('tenant')->group(function () {
            Route::get('outlets', [\App\Http\Controllers\Api\V1\OutletController::class, 'index']);
            Route::apiResource('outlets', \App\Http\Controllers\Api\V1\OutletController::class)->except(['index']);
            // Actually outlets nested; handle below

            // users/memberships
            Route::get('memberships', [\App\Http\Controllers\Api\V1\MembershipController::class, 'index']);
            Route::post('memberships/invite', [\App\Http\Controllers\Api\V1\MembershipController::class, 'invite']);
            Route::put('memberships/{membership}', [\App\Http\Controllers\Api\V1\MembershipController::class, 'update']);
            Route::delete('memberships/{membership}', [\App\Http\Controllers\Api\V1\MembershipController::class, 'destroy']);

            // roles/permissions
            Route::get('roles', [\App\Http\Controllers\Api\V1\RoleController::class, 'index']);
            Route::post('roles', [\App\Http\Controllers\Api\V1\RoleController::class, 'store']);
            Route::put('roles/{role}', [\App\Http\Controllers\Api\V1\RoleController::class, 'update']);
            Route::delete('roles/{role}', [\App\Http\Controllers\Api\V1\RoleController::class, 'destroy']);
            Route::get('permissions', [\App\Http\Controllers\Api\V1\PermissionController::class, 'index']);

            // devices
            Route::apiResource('devices', \App\Http\Controllers\Api\V1\DeviceController::class);

            // catalog
            Route::apiResource('categories', \App\Http\Controllers\Api\V1\CategoryController::class);
            Route::apiResource('products', \App\Http\Controllers\Api\V1\ProductController::class);
            Route::get('products/search', [\App\Http\Controllers\Api\V1\ProductController::class, 'search']);
            Route::apiResource('customers', \App\Http\Controllers\Api\V1\CustomerController::class);
            Route::apiResource('suppliers', \App\Http\Controllers\Api\V1\SupplierController::class);

            // inventory
            Route::get('inventory/balances', [\App\Http\Controllers\Api\V1\InventoryController::class, 'balances']);
            Route::post('inventory/adjust', [\App\Http\Controllers\Api\V1\InventoryController::class, 'adjust']);
            Route::get('inventory/movements', [\App\Http\Controllers\Api\V1\InventoryController::class, 'movements']);
            Route::apiResource('stock-transfers', \App\Http\Controllers\Api\V1\StockTransferController::class);
            Route::post('stock-transfers/{stockTransfer}/complete', [\App\Http\Controllers\Api\V1\StockTransferController::class, 'complete']);

            // taxes/discounts
            Route::apiResource('tax-rates', \App\Http\Controllers\Api\V1\TaxRateController::class);
            Route::apiResource('discounts', \App\Http\Controllers\Api\V1\DiscountController::class);

            // sales
            Route::get('sales', [\App\Http\Controllers\Api\V1\SaleController::class, 'index']);
            Route::post('sales', [\App\Http\Controllers\Api\V1\SaleController::class, 'store']);
            Route::get('sales/{sale}', [\App\Http\Controllers\Api\V1\SaleController::class, 'show']);
            Route::post('sales/{sale}/void', [\App\Http\Controllers\Api\V1\SaleController::class, 'void']);
            Route::get('sales/{sale}/receipt', [\App\Http\Controllers\Api\V1\SaleController::class, 'receipt']);

            // payments
            Route::post('payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'store']);
            Route::post('payments/{payment}/refund', [\App\Http\Controllers\Api\V1\PaymentController::class, 'refund']);

            // refunds
            Route::get('refunds', [\App\Http\Controllers\Api\V1\RefundController::class, 'index']);
            Route::post('refunds/{refund}/approve', [\App\Http\Controllers\Api\V1\RefundController::class, 'approve']);
            Route::post('refunds/{refund}/reject', [\App\Http\Controllers\Api\V1\RefundController::class, 'reject']);

            // purchase orders
            Route::apiResource('purchase-orders', \App\Http\Controllers\Api\V1\PurchaseOrderController::class);
            Route::post('purchase-orders/{purchaseOrder}/approve', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'approve']);
            Route::post('purchase-orders/{purchaseOrder}/receive', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'receive']);

            // cash registers/shifts
            Route::apiResource('cash-registers', \App\Http\Controllers\Api\V1\CashRegisterController::class);
            Route::post('shifts/open', [\App\Http\Controllers\Api\V1\ShiftController::class, 'open']);
            Route::post('shifts/{shift}/close', [\App\Http\Controllers\Api\V1\ShiftController::class, 'close']);
            Route::get('shifts', [\App\Http\Controllers\Api\V1\ShiftController::class, 'index']);
            Route::post('shifts/{shift}/cash-movement', [\App\Http\Controllers\Api\V1\ShiftController::class, 'cashMovement']);

            // reports
            Route::get('reports/sales-summary', [\App\Http\Controllers\Api\V1\ReportController::class, 'salesSummary']);
            Route::get('reports/sales-by-product', [\App\Http\Controllers\Api\V1\ReportController::class, 'salesByProduct']);
            Route::get('reports/inventory-balances', [\App\Http\Controllers\Api\V1\ReportController::class, 'inventoryBalances']);
            Route::get('reports/low-stock', [\App\Http\Controllers\Api\V1\ReportController::class, 'lowStock']);

            // audit
            Route::get('audit-logs', [\App\Http\Controllers\Api\V1\AuditLogController::class, 'index']);

            // sync
            Route::get('sync/cursor', [\App\Http\Controllers\Api\V1\SyncController::class, 'cursor']);
            Route::post('sync/push', [\App\Http\Controllers\Api\V1\SyncController::class, 'push']);
            Route::get('sync/pull', [\App\Http\Controllers\Api\V1\SyncController::class, 'pull']);

            // webhooks (payment)
            Route::post('webhooks/payment/{provider}', [\App\Http\Controllers\Api\V1\WebhookController::class, 'payment']);
        });
    });
});
