<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform defaults
    |--------------------------------------------------------------------------
    |
    | Editable at runtime from the admin dashboard (stored in
    | `platform_settings`). Values here are the fallbacks shown before an
    | administrator saves an override.
    |
    */

    'defaults' => [
        'support.email' => [
            'value' => 'support@velora.test',
            'description' => 'Reply-to address shown on support tickets.',
        ],
        'support.response_hours' => [
            'value' => 24,
            'description' => 'Target first-response time in hours.',
        ],
        'signup.enabled' => [
            'value' => true,
            'description' => 'Allow new businesses to self-register.',
        ],
        'storefront.enabled' => [
            'value' => true,
            'description' => 'Allow tenants to publish an online storefront.',
        ],
        'billing.default_trial_days' => [
            'value' => 30,
            'description' => 'Trial length applied to new subscriptions.',
        ],
        'maintenance.message' => [
            'value' => '',
            'description' => 'Shown in the app while maintenance mode is on.',
        ],
    ],

];
