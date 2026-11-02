<?php

/*
|--------------------------------------------------------------------------
| Velora subscription configuration
|--------------------------------------------------------------------------
|
| This file is the single source of truth for plan defaults. The
| `subscription_plans` table is seeded from it and can then be edited by a
| platform administrator; the database row wins at runtime so pricing and
| limits can change without a deploy.
|
| Prices are placeholders in the smallest currency unit (IDR has no minor
| unit, so 149000 means Rp 149.000). Replace them before going live.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    */

    'trial_days' => env('SUBSCRIPTION_TRIAL_DAYS', 30),

    // Days a business keeps access after a failed renewal before the
    // subscription is moved to `expired`.
    'grace_period_days' => env('SUBSCRIPTION_GRACE_DAYS', 3),

    'currency' => env('SUBSCRIPTION_CURRENCY', 'IDR'),

    // Trial reminder thresholds (days remaining) surfaced to the UI.
    'trial_reminder_days' => [14, 7, 3, 1],

    // Where the payment provider should send the customer back to.
    'checkout_return_url' => env('SUBSCRIPTION_RETURN_URL', env('APP_URL', 'http://localhost:3000').'/settings/subscription'),

    /*
    |--------------------------------------------------------------------------
    | Payment providers
    |--------------------------------------------------------------------------
    |
    | `manual` is always available and is settled by an administrator. The
    | automated providers only become usable once their credentials are set.
    | No provider is simulated: an unconfigured provider returns a clear
    | "not configured" error instead of pretending a charge succeeded.
    |
    */

    'providers' => [
        'manual' => [
            'enabled' => env('SUBSCRIPTION_MANUAL_ENABLED', true),
            'label' => 'Bank transfer',
            'instructions' => env('SUBSCRIPTION_MANUAL_INSTRUCTIONS', 'Transfer the invoice total to the account shown on the invoice, then reply with the transfer reference. An administrator confirms the payment.'),
            'bank_name' => env('SUBSCRIPTION_BANK_NAME', ''),
            'account_name' => env('SUBSCRIPTION_BANK_ACCOUNT_NAME', ''),
            'account_number' => env('SUBSCRIPTION_BANK_ACCOUNT_NUMBER', ''),
        ],
        'midtrans' => [
            'enabled' => env('MIDTRANS_ENABLED', false),
            'label' => 'Midtrans',
            'server_key' => env('MIDTRANS_SERVER_KEY', ''),
            'client_key' => env('MIDTRANS_CLIENT_KEY', ''),
            'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
            'snap_url' => env('MIDTRANS_SNAP_URL', 'https://app.sandbox.midtrans.com/snap/v1/transactions'),
            'api_url' => env('MIDTRANS_API_URL', 'https://api.sandbox.midtrans.com/v2'),
        ],
        'xendit' => [
            'enabled' => env('XENDIT_ENABLED', false),
            'label' => 'Xendit',
            'secret_key' => env('XENDIT_SECRET_KEY', ''),
            'callback_token' => env('XENDIT_CALLBACK_TOKEN', ''),
            'invoice_url' => env('XENDIT_INVOICE_URL', 'https://api.xendit.co/v2/invoices'),
        ],
    ],

    // Optional local-only sandbox used by automated UI verification. It is
    // disabled by default and refuses to run outside the local environment.
    'sandbox' => [
        'enabled' => env('SUBSCRIPTION_SANDBOX_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | `limits` uses null for "unlimited". `features` is the plan's feature
    | catalogue; any key missing here is treated as disabled.
    |
    */

    'plans' => [

        'trial' => [
            'name' => 'Free Trial',
            'description' => 'Everything you need to try Velora for 30 days.',
            'sort_order' => 0,
            'is_public' => true,
            'trial_days' => env('SUBSCRIPTION_TRIAL_DAYS', 30),
            'prices' => ['monthly' => 0, 'yearly' => 0],
            'limits' => [
                'businesses' => 1,
                // Trial limited to 1 outlet — matches the single-outlet entry
                // point. Pro scales to 10 for multi-site operations.
                'outlets' => 1,
                'users' => 3,
                'products' => 100,
                'customers' => 100,
                'transactions_per_month' => 100,
                'exports_per_month' => 10,
            ],
            'features' => [
                'pos' => true,
                'inventory' => true,
                'customers' => true,
                'suppliers' => true,
                'purchasing' => true,
                'sales_reports' => true,
                'dashboard' => true,
                'transaction_history' => true,
                'standard_export' => true,
                'multi_outlet' => false,
                'advanced_analytics' => false,
                'advanced_reports' => false,
                'custom_reports' => false,
                'outlet_comparison' => false,
                'business_insights' => false,
                'advanced_export' => false,
                'advanced_inventory' => false,
                'advanced_customers' => false,
                'advanced_suppliers' => false,
                'advanced_purchasing' => false,
                'advanced_employees' => false,
                'priority_support' => false,
            ],
        ],

        'basic' => [
            'name' => 'Basic',
            'description' => 'For a single shop that needs the full counter and back office.',
            'sort_order' => 1,
            'is_public' => true,
            'trial_days' => 0,
            'prices' => [
                'monthly' => (int) env('SUBSCRIPTION_BASIC_MONTHLY', 69000),
                'yearly' => (int) env('SUBSCRIPTION_BASIC_YEARLY', 720000),
            ],
            'limits' => [
                'businesses' => 1,
                'outlets' => 1,
                'users' => 3,
                'products' => 500,
                'customers' => 1000,
                'transactions_per_month' => 2000,
                'exports_per_month' => 100,
            ],
            'features' => [
                'pos' => true,
                'inventory' => true,
                'customers' => true,
                'suppliers' => true,
                'purchasing' => true,
                'sales_reports' => true,
                'dashboard' => true,
                'transaction_history' => true,
                'standard_export' => true,
                'multi_outlet' => false,
                'advanced_analytics' => false,
                'advanced_reports' => false,
                'custom_reports' => false,
                'outlet_comparison' => false,
                'business_insights' => false,
                'advanced_export' => false,
                'advanced_inventory' => false,
                'advanced_customers' => false,
                'advanced_suppliers' => false,
                'advanced_purchasing' => false,
                'advanced_employees' => false,
                'priority_support' => false,
            ],
        ],

        'pro' => [
            'name' => 'Pro',
            'description' => 'For growing retailers with several outlets and deeper reporting.',
            'sort_order' => 2,
            'is_public' => true,
            'trial_days' => 0,
            'prices' => [
                'monthly' => (int) env('SUBSCRIPTION_PRO_MONTHLY', 89000),
                'yearly' => (int) env('SUBSCRIPTION_PRO_YEARLY', 890000),
            ],
            'limits' => [
                'businesses' => 1,
                'outlets' => 10,
                'users' => 20,
                'products' => null,
                'customers' => null,
                'transactions_per_month' => null,
                'exports_per_month' => null,
            ],
            'features' => [
                'pos' => true,
                'inventory' => true,
                'customers' => true,
                'suppliers' => true,
                'purchasing' => true,
                'sales_reports' => true,
                'dashboard' => true,
                'transaction_history' => true,
                'standard_export' => true,
                'multi_outlet' => true,
                'advanced_analytics' => true,
                'advanced_reports' => true,
                'custom_reports' => true,
                'outlet_comparison' => true,
                'business_insights' => true,
                'advanced_export' => true,
                'advanced_inventory' => true,
                'advanced_customers' => true,
                'advanced_suppliers' => true,
                'advanced_purchasing' => true,
                'advanced_employees' => true,
                'priority_support' => true,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Feature catalogue (used by the pricing table and admin UI)
    |--------------------------------------------------------------------------
    */

    'feature_catalog' => [
        'pos' => ['label' => 'Point of sale', 'group' => 'Core'],
        'inventory' => ['label' => 'Inventory management', 'group' => 'Core'],
        'customers' => ['label' => 'Customer management', 'group' => 'Core'],
        'suppliers' => ['label' => 'Supplier management', 'group' => 'Core'],
        'purchasing' => ['label' => 'Purchasing & purchase orders', 'group' => 'Core'],
        'sales_reports' => ['label' => 'Standard sales reports', 'group' => 'Core'],
        'dashboard' => ['label' => 'Dashboard overview', 'group' => 'Core'],
        'transaction_history' => ['label' => 'Transaction history', 'group' => 'Core'],
        'standard_export' => ['label' => 'Standard CSV export', 'group' => 'Core'],
        'multi_outlet' => ['label' => 'Multi-outlet management', 'group' => 'Growth'],
        'advanced_analytics' => ['label' => 'Advanced sales analytics', 'group' => 'Growth'],
        'advanced_reports' => ['label' => 'Advanced financial reports', 'group' => 'Growth'],
        'custom_reports' => ['label' => 'Custom date-range reports', 'group' => 'Growth'],
        'outlet_comparison' => ['label' => 'Outlet performance comparison', 'group' => 'Growth'],
        'business_insights' => ['label' => 'Business performance insights', 'group' => 'Growth'],
        'advanced_export' => ['label' => 'Advanced CSV & Excel export', 'group' => 'Growth'],
        'advanced_inventory' => ['label' => 'Advanced inventory management', 'group' => 'Growth'],
        'advanced_customers' => ['label' => 'Advanced customer management', 'group' => 'Growth'],
        'advanced_suppliers' => ['label' => 'Advanced supplier management', 'group' => 'Growth'],
        'advanced_purchasing' => ['label' => 'Complete purchasing management', 'group' => 'Growth'],
        'advanced_employees' => ['label' => 'Advanced employee management', 'group' => 'Growth'],
        'priority_support' => ['label' => 'Priority support', 'group' => 'Support'],
    ],

    // Metrics exposed by the usage API, with the label and how they count.
    'usage_metrics' => [
        'products' => ['label' => 'Products', 'source' => 'count', 'model' => 'products'],
        'customers' => ['label' => 'Customers', 'source' => 'count', 'model' => 'customers'],
        'outlets' => ['label' => 'Outlets', 'source' => 'count', 'model' => 'outlets'],
        'users' => ['label' => 'Users', 'source' => 'count', 'model' => 'users'],
        'transactions_per_month' => ['label' => 'Transactions this month', 'source' => 'count', 'model' => 'sales'],
        'exports_per_month' => ['label' => 'Exports this month', 'source' => 'counter'],
    ],

];
