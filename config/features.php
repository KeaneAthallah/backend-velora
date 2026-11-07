<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    |
    | Switches for endpoints that are deliberately gated off. Unlike the plan
    | features in `config/subscriptions.php` these are deployment-wide, not
    | per-tenant, and are read straight from the environment.
    |
    */

    /*
     * Read-only half of the reorder copilot: `GET /reorder/forecast`, which
     * projects demand from recipes and sales and reports waste risk from
     * `ingredients.shelf_life_days`. Uses only shipped tables.
     */
    'reorder_copilot' => (bool) env('FEATURES_REORDER_COPILOT', true),

    /*
     * Write half: `POST /reorder/suggest|items/{item}/approve|reject`. Those
     * draft purchase orders whose lines are ingredients, while
     * `purchase_order_items` is product-based (`product_id` is NOT NULL). The
     * columns exist, but flipping this on writes lines the purchasing screens
     * and goods receipts do not understand. Off until ingredient lines are a
     * real purchasing concept.
     */
    'reorder_purchase_orders' => (bool) env('FEATURES_REORDER_PURCHASE_ORDERS', false),

];
