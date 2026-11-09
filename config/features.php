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
     * draft a purchase order whose lines carry `ingredient_id` plus the product
     * the ingredient belongs to. The purchasing screens display them, and
     * receiving one restocks `ingredient_balances` (with an
     * `ingredient_movements` receipt) instead of product inventory.
     */
    'reorder_purchase_orders' => (bool) env('FEATURES_REORDER_PURCHASE_ORDERS', true),

];
