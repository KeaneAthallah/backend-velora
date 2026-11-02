<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\CashRegisterController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ChannelController;
use App\Http\Controllers\Api\V1\ChannelWebhookController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\IngredientController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\KitchenController;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\ModifierGroupController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OutletController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\ReorderController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\TableController;
use App\Http\Controllers\Api\V1\TaxRateController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // auth
    // Credential and email-sending endpoints are throttled per IP so they
    // cannot be used to brute-force passwords or spam reset mail.
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:10,1');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:10,1');

    // Google sign-in. The redirect endpoint returns the authorize URL, Google
    // calls back on the API origin, and the browser is then handed to the
    // frontend with a one-time code that the exchange endpoint trades for a
    // Sanctum token. No session is involved, so state is encrypted and expiring.
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->middleware('throttle:10,1');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->middleware('throttle:30,1');
    Route::post('auth/google/exchange', [GoogleAuthController::class, 'exchange'])
        ->middleware('throttle:10,1');

    // public channel partner ingress (signed with the channel secret), throttled
    // per channel token so a burst or a replay loop cannot flood order creation
    Route::post('channel-webhooks/{token}', [ChannelWebhookController::class, 'receive'])
        ->middleware('throttle:channel-webhook');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [MeController::class, 'show']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // businesses (no tenant context required for list/create)
        Route::get('businesses', [BusinessController::class, 'index']);
        Route::post('businesses', [BusinessController::class, 'store']);
        Route::get('businesses/{business}', [BusinessController::class, 'show']);
        Route::put('businesses/{business}', [BusinessController::class, 'update']);
        Route::delete('businesses/{business}', [BusinessController::class, 'destroy']);

        // tenant-scoped
        Route::middleware('tenant')->group(function () {
            // apiResource already registers GET /outlets (index).
            Route::apiResource('outlets', OutletController::class);

            // users/memberships
            Route::get('memberships', [MembershipController::class, 'index']);
            Route::post('memberships/invite', [MembershipController::class, 'invite']);
            Route::put('memberships/{membership}', [MembershipController::class, 'update']);
            Route::delete('memberships/{membership}', [MembershipController::class, 'destroy']);

            // roles/permissions
            Route::get('roles', [RoleController::class, 'index']);
            Route::post('roles', [RoleController::class, 'store']);
            Route::put('roles/{role}', [RoleController::class, 'update']);
            Route::delete('roles/{role}', [RoleController::class, 'destroy']);
            Route::get('permissions', [PermissionController::class, 'index']);

            // devices
            Route::apiResource('devices', DeviceController::class);

            // menu engineering
            Route::get('menu', [MenuController::class, 'menu']);
            Route::get('menu/availability', [MenuController::class, 'availability']);
            Route::put('menu/availability', [MenuController::class, 'updateAvailability']);
            Route::post('menu/items/{product}/86', [MenuController::class, 'markUnavailable']);
            Route::post('menu/items/{product}/restore', [MenuController::class, 'restore']);
            Route::get('dayparts', [MenuController::class, 'dayparts']);
            Route::post('dayparts', [MenuController::class, 'storeDaypart']);
            Route::put('dayparts/{daypart}', [MenuController::class, 'updateDaypart']);
            Route::delete('dayparts/{daypart}', [MenuController::class, 'destroyDaypart']);
            Route::put('products/{product}/dayparts', [MenuController::class, 'productDayparts']);
            Route::apiResource('modifier-groups', ModifierGroupController::class)
                ->parameters(['modifier-groups' => 'modifierGroup']);
            Route::post('modifier-groups/{modifierGroup}/attach', [ModifierGroupController::class, 'attach']);
            Route::post('modifier-groups/{modifierGroup}/detach', [ModifierGroupController::class, 'detach']);
            Route::get('modifier-groups/{modifierGroup}/options', [ModifierGroupController::class, 'options']);
            Route::post('modifier-groups/{modifierGroup}/options', [ModifierGroupController::class, 'storeOption']);
            Route::put('modifier-options/{modifierOption}', [ModifierGroupController::class, 'updateOption']);
            Route::delete('modifier-options/{modifierOption}', [ModifierGroupController::class, 'destroyOption']);

            // catalog
            Route::apiResource('categories', CategoryController::class);
            Route::apiResource('products', ProductController::class);
            Route::get('products/search', [ProductController::class, 'search']);
            Route::apiResource('customers', CustomerController::class);
            Route::apiResource('suppliers', SupplierController::class);

            // loyalty & promotions
            Route::get('loyalty/program', [LoyaltyController::class, 'show']);
            Route::put('loyalty/program', [LoyaltyController::class, 'update']);
            Route::get('loyalty/transactions', [LoyaltyController::class, 'transactions']);
            Route::post('customers/{customer}/loyalty/adjust', [LoyaltyController::class, 'adjust']);
            Route::get('promotions', [PromotionController::class, 'index']);
            Route::post('promotions', [PromotionController::class, 'store']);
            Route::post('promotions/preview', [PromotionController::class, 'preview']);
            Route::put('promotions/{promotion}', [PromotionController::class, 'update']);
            Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy']);

            // online & delivery channels
            Route::get('channels', [ChannelController::class, 'index']);
            Route::get('channels/summary', [ChannelController::class, 'summary']);
            Route::post('channels', [ChannelController::class, 'store']);
            Route::put('channels/{channel}', [ChannelController::class, 'update']);
            Route::delete('channels/{channel}', [ChannelController::class, 'destroy']);
            Route::post('channels/{channel}/rotate-secret', [ChannelController::class, 'rotateSecret']);
            Route::get('channels/{channel}/mappings', [ChannelController::class, 'mappings']);
            Route::put('channels/{channel}/mappings', [ChannelController::class, 'syncMappings']);
            Route::get('channels/{channel}/orders', [ChannelController::class, 'orders']);
            Route::post('channels/{channel}/orders/{sale}/accept', [ChannelController::class, 'acceptOrder']);
            Route::post('channels/{channel}/orders/{sale}/reject', [ChannelController::class, 'rejectOrder']);
            Route::get('channels/{channel}/events', [ChannelController::class, 'events']);
            Route::post('channels/{channel}/events/{event}/ack', [ChannelController::class, 'ackEvent']);
            Route::post('channels/{channel}/menu/push', [ChannelController::class, 'pushMenu']);

            // inventory
            Route::get('inventory/balances', [InventoryController::class, 'balances']);
            Route::post('inventory/adjust', [InventoryController::class, 'adjust']);
            Route::get('inventory/movements', [InventoryController::class, 'movements']);
            Route::apiResource('stock-transfers', StockTransferController::class);
            Route::post('stock-transfers/{stockTransfer}/complete', [StockTransferController::class, 'complete']);

            // ingredients & recipes (BOM)
            Route::get('ingredients', [IngredientController::class, 'index']);
            Route::post('ingredients', [IngredientController::class, 'store']);
            Route::get('ingredients/balances', [IngredientController::class, 'balances']);
            Route::post('ingredients/adjust', [IngredientController::class, 'adjust']);
            Route::put('ingredients/{ingredient}', [IngredientController::class, 'update']);
            Route::delete('ingredients/{ingredient}', [IngredientController::class, 'destroy']);
            Route::get('ingredient-movements', [IngredientController::class, 'movements']);
            Route::get('recipes', [RecipeController::class, 'index']);
            Route::get('products/{product}/recipe', [RecipeController::class, 'show']);
            Route::put('products/{product}/recipe', [RecipeController::class, 'sync']);

            // taxes/discounts
            Route::apiResource('tax-rates', TaxRateController::class);
            Route::apiResource('discounts', DiscountController::class);

            // sales
            Route::get('sales', [SaleController::class, 'index']);
            Route::post('sales', [SaleController::class, 'store']);
            Route::get('sales/{sale}', [SaleController::class, 'show']);
            Route::post('sales/{sale}/void', [SaleController::class, 'void']);
            Route::get('sales/{sale}/receipt', [SaleController::class, 'receipt']);

            // payments
            Route::get('payment-methods', [PaymentMethodController::class, 'index']);
            Route::post('payment-methods', [PaymentMethodController::class, 'store']);
            Route::put('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update']);
            Route::delete('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
            Route::post('sales/{sale}/payments', [PaymentController::class, 'storeForSale']);
            Route::post('payments', [PaymentController::class, 'store']);
            Route::post('payments/{payment}/refund', [PaymentController::class, 'refund']);

            // orders (open tabs, kitchen flow, pay later)
            Route::get('orders', [OrderController::class, 'index']);
            Route::post('orders', [OrderController::class, 'store']);
            Route::get('orders/{sale}', [OrderController::class, 'show']);
            Route::put('orders/{sale}', [OrderController::class, 'update']);
            Route::post('orders/{sale}/status', [OrderController::class, 'status']);
            Route::post('orders/{sale}/checkout', [OrderController::class, 'checkout']);
            Route::post('orders/{sale}/cancel', [OrderController::class, 'cancel']);

            // floor plan (areas + dining tables)
            Route::get('tables', [TableController::class, 'index']);
            Route::post('tables', [TableController::class, 'store']);
            Route::put('tables/{table}', [TableController::class, 'update']);
            Route::delete('tables/{table}', [TableController::class, 'destroy']);
            Route::post('tables/{table}/status', [TableController::class, 'status']);
            Route::get('floor-areas', [TableController::class, 'areas']);
            Route::post('floor-areas', [TableController::class, 'storeArea']);
            Route::put('floor-areas/{area}', [TableController::class, 'updateArea']);
            Route::delete('floor-areas/{area}', [TableController::class, 'destroyArea']);

            // kitchen display + ticket printing
            Route::get('kitchen/stations', [KitchenController::class, 'stations']);
            Route::post('kitchen/stations', [KitchenController::class, 'storeStation']);
            Route::put('kitchen/stations/{station}', [KitchenController::class, 'updateStation']);
            Route::delete('kitchen/stations/{station}', [KitchenController::class, 'destroyStation']);
            Route::get('kitchen/tickets', [KitchenController::class, 'tickets']);
            Route::post('kitchen/tickets/{ticket}/status', [KitchenController::class, 'ticketStatus']);
            Route::get('kitchen/tickets/{ticket}/print', [KitchenController::class, 'printTicket']);
            Route::post('kitchen/tickets/{ticket}/printed', [KitchenController::class, 'markPrinted']);

            // refunds
            Route::get('refunds', [RefundController::class, 'index']);
            Route::post('refunds/{refund}/approve', [RefundController::class, 'approve']);
            Route::post('refunds/{refund}/reject', [RefundController::class, 'reject']);

            // purchase orders
            Route::apiResource('purchase-orders', PurchaseOrderController::class);
            Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
            Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);

            // reorder copilot (demand forecast + constrained PO draft + approval)
            Route::get('reorder/forecast', [ReorderController::class, 'forecast']);
            Route::post('reorder/suggest', [ReorderController::class, 'suggest']);
            Route::post('reorder/items/{purchaseOrderItemId}/approve', [ReorderController::class, 'approve']);
            Route::post('reorder/items/{purchaseOrderItemId}/reject', [ReorderController::class, 'reject']);

            // cash registers/shifts
            Route::apiResource('cash-registers', CashRegisterController::class);
            Route::post('shifts/open', [ShiftController::class, 'open']);
            Route::post('shifts/{shift}/close', [ShiftController::class, 'close']);
            Route::get('shifts', [ShiftController::class, 'index']);
            Route::post('shifts/{shift}/cash-movement', [ShiftController::class, 'cashMovement']);

            // reports
            Route::get('reports/sales-summary', [ReportController::class, 'salesSummary']);
            Route::get('reports/sales-by-product', [ReportController::class, 'salesByProduct']);
            Route::get('reports/inventory-balances', [ReportController::class, 'inventoryBalances']);
            Route::get('reports/low-stock', [ReportController::class, 'lowStock']);

            // audit
            Route::get('audit-logs', [AuditLogController::class, 'index']);

            // sync
            Route::get('sync/bootstrap', [SyncController::class, 'bootstrap']);
            Route::get('sync/cursor', [SyncController::class, 'cursor']);
            Route::post('sync/push', [SyncController::class, 'push']);
            Route::get('sync/pull', [SyncController::class, 'pull']);

            // webhooks (payment)
            Route::post('webhooks/payment/{provider}', [WebhookController::class, 'payment']);
        });
    });
});
