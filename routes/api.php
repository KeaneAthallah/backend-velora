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

            // menu engineering
            Route::get('menu', [\App\Http\Controllers\Api\V1\MenuController::class, 'menu']);
            Route::get('menu/availability', [\App\Http\Controllers\Api\V1\MenuController::class, 'availability']);
            Route::put('menu/availability', [\App\Http\Controllers\Api\V1\MenuController::class, 'updateAvailability']);
            Route::post('menu/items/{product}/86', [\App\Http\Controllers\Api\V1\MenuController::class, 'markUnavailable']);
            Route::post('menu/items/{product}/restore', [\App\Http\Controllers\Api\V1\MenuController::class, 'restore']);
            Route::get('dayparts', [\App\Http\Controllers\Api\V1\MenuController::class, 'dayparts']);
            Route::post('dayparts', [\App\Http\Controllers\Api\V1\MenuController::class, 'storeDaypart']);
            Route::put('dayparts/{daypart}', [\App\Http\Controllers\Api\V1\MenuController::class, 'updateDaypart']);
            Route::delete('dayparts/{daypart}', [\App\Http\Controllers\Api\V1\MenuController::class, 'destroyDaypart']);
            Route::put('products/{product}/dayparts', [\App\Http\Controllers\Api\V1\MenuController::class, 'productDayparts']);
            Route::apiResource('modifier-groups', \App\Http\Controllers\Api\V1\ModifierGroupController::class)
                ->parameters(['modifier-groups' => 'modifierGroup']);
            Route::post('modifier-groups/{modifierGroup}/attach', [\App\Http\Controllers\Api\V1\ModifierGroupController::class, 'attach']);
            Route::post('modifier-groups/{modifierGroup}/detach', [\App\Http\Controllers\Api\V1\ModifierGroupController::class, 'detach']);
            Route::get('modifier-groups/{modifierGroup}/options', [\App\Http\Controllers\Api\V1\ModifierGroupController::class, 'options']);
            Route::post('modifier-groups/{modifierGroup}/options', [\App\Http\Controllers\Api\V1\ModifierGroupController::class, 'storeOption']);
            Route::put('modifier-options/{modifierOption}', [\App\Http\Controllers\Api\V1\ModifierGroupController::class, 'updateOption']);
            Route::delete('modifier-options/{modifierOption}', [\App\Http\Controllers\Api\V1\ModifierGroupController::class, 'destroyOption']);

            // catalog
            Route::apiResource('categories', \App\Http\Controllers\Api\V1\CategoryController::class);
            Route::apiResource('products', \App\Http\Controllers\Api\V1\ProductController::class);
            Route::get('products/search', [\App\Http\Controllers\Api\V1\ProductController::class, 'search']);
            Route::apiResource('customers', \App\Http\Controllers\Api\V1\CustomerController::class);
            Route::apiResource('suppliers', \App\Http\Controllers\Api\V1\SupplierController::class);

            // loyalty & promotions
            Route::get('loyalty/program', [\App\Http\Controllers\Api\V1\LoyaltyController::class, 'show']);
            Route::put('loyalty/program', [\App\Http\Controllers\Api\V1\LoyaltyController::class, 'update']);
            Route::get('loyalty/transactions', [\App\Http\Controllers\Api\V1\LoyaltyController::class, 'transactions']);
            Route::post('customers/{customer}/loyalty/adjust', [\App\Http\Controllers\Api\V1\LoyaltyController::class, 'adjust']);
            Route::get('promotions', [\App\Http\Controllers\Api\V1\PromotionController::class, 'index']);
            Route::post('promotions', [\App\Http\Controllers\Api\V1\PromotionController::class, 'store']);
            Route::post('promotions/preview', [\App\Http\Controllers\Api\V1\PromotionController::class, 'preview']);
            Route::put('promotions/{promotion}', [\App\Http\Controllers\Api\V1\PromotionController::class, 'update']);
            Route::delete('promotions/{promotion}', [\App\Http\Controllers\Api\V1\PromotionController::class, 'destroy']);

            // inventory
            Route::get('inventory/balances', [\App\Http\Controllers\Api\V1\InventoryController::class, 'balances']);
            Route::post('inventory/adjust', [\App\Http\Controllers\Api\V1\InventoryController::class, 'adjust']);
            Route::get('inventory/movements', [\App\Http\Controllers\Api\V1\InventoryController::class, 'movements']);
            Route::apiResource('stock-transfers', \App\Http\Controllers\Api\V1\StockTransferController::class);
            Route::post('stock-transfers/{stockTransfer}/complete', [\App\Http\Controllers\Api\V1\StockTransferController::class, 'complete']);

            // ingredients & recipes (BOM)
            Route::get('ingredients', [\App\Http\Controllers\Api\V1\IngredientController::class, 'index']);
            Route::post('ingredients', [\App\Http\Controllers\Api\V1\IngredientController::class, 'store']);
            Route::get('ingredients/balances', [\App\Http\Controllers\Api\V1\IngredientController::class, 'balances']);
            Route::post('ingredients/adjust', [\App\Http\Controllers\Api\V1\IngredientController::class, 'adjust']);
            Route::put('ingredients/{ingredient}', [\App\Http\Controllers\Api\V1\IngredientController::class, 'update']);
            Route::delete('ingredients/{ingredient}', [\App\Http\Controllers\Api\V1\IngredientController::class, 'destroy']);
            Route::get('ingredient-movements', [\App\Http\Controllers\Api\V1\IngredientController::class, 'movements']);
            Route::get('recipes', [\App\Http\Controllers\Api\V1\RecipeController::class, 'index']);
            Route::get('products/{product}/recipe', [\App\Http\Controllers\Api\V1\RecipeController::class, 'show']);
            Route::put('products/{product}/recipe', [\App\Http\Controllers\Api\V1\RecipeController::class, 'sync']);

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
            Route::get('payment-methods', [\App\Http\Controllers\Api\V1\PaymentMethodController::class, 'index']);
            Route::post('payment-methods', [\App\Http\Controllers\Api\V1\PaymentMethodController::class, 'store']);
            Route::put('payment-methods/{paymentMethod}', [\App\Http\Controllers\Api\V1\PaymentMethodController::class, 'update']);
            Route::delete('payment-methods/{paymentMethod}', [\App\Http\Controllers\Api\V1\PaymentMethodController::class, 'destroy']);
            Route::post('sales/{sale}/payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'storeForSale']);
            Route::post('payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'store']);
            Route::post('payments/{payment}/refund', [\App\Http\Controllers\Api\V1\PaymentController::class, 'refund']);

            // orders (open tabs, kitchen flow, pay later)
            Route::get('orders', [\App\Http\Controllers\Api\V1\OrderController::class, 'index']);
            Route::post('orders', [\App\Http\Controllers\Api\V1\OrderController::class, 'store']);
            Route::get('orders/{sale}', [\App\Http\Controllers\Api\V1\OrderController::class, 'show']);
            Route::put('orders/{sale}', [\App\Http\Controllers\Api\V1\OrderController::class, 'update']);
            Route::post('orders/{sale}/status', [\App\Http\Controllers\Api\V1\OrderController::class, 'status']);
            Route::post('orders/{sale}/checkout', [\App\Http\Controllers\Api\V1\OrderController::class, 'checkout']);
            Route::post('orders/{sale}/cancel', [\App\Http\Controllers\Api\V1\OrderController::class, 'cancel']);

            // floor plan (areas + dining tables)
            Route::get('tables', [\App\Http\Controllers\Api\V1\TableController::class, 'index']);
            Route::post('tables', [\App\Http\Controllers\Api\V1\TableController::class, 'store']);
            Route::put('tables/{table}', [\App\Http\Controllers\Api\V1\TableController::class, 'update']);
            Route::delete('tables/{table}', [\App\Http\Controllers\Api\V1\TableController::class, 'destroy']);
            Route::post('tables/{table}/status', [\App\Http\Controllers\Api\V1\TableController::class, 'status']);
            Route::get('floor-areas', [\App\Http\Controllers\Api\V1\TableController::class, 'areas']);
            Route::post('floor-areas', [\App\Http\Controllers\Api\V1\TableController::class, 'storeArea']);
            Route::put('floor-areas/{area}', [\App\Http\Controllers\Api\V1\TableController::class, 'updateArea']);
            Route::delete('floor-areas/{area}', [\App\Http\Controllers\Api\V1\TableController::class, 'destroyArea']);

            // kitchen display + ticket printing
            Route::get('kitchen/stations', [\App\Http\Controllers\Api\V1\KitchenController::class, 'stations']);
            Route::post('kitchen/stations', [\App\Http\Controllers\Api\V1\KitchenController::class, 'storeStation']);
            Route::put('kitchen/stations/{station}', [\App\Http\Controllers\Api\V1\KitchenController::class, 'updateStation']);
            Route::delete('kitchen/stations/{station}', [\App\Http\Controllers\Api\V1\KitchenController::class, 'destroyStation']);
            Route::get('kitchen/tickets', [\App\Http\Controllers\Api\V1\KitchenController::class, 'tickets']);
            Route::post('kitchen/tickets/{ticket}/status', [\App\Http\Controllers\Api\V1\KitchenController::class, 'ticketStatus']);
            Route::get('kitchen/tickets/{ticket}/print', [\App\Http\Controllers\Api\V1\KitchenController::class, 'printTicket']);
            Route::post('kitchen/tickets/{ticket}/printed', [\App\Http\Controllers\Api\V1\KitchenController::class, 'markPrinted']);

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
