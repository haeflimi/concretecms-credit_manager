<?php

return [
    'payment_methods' => [
        'payrexx' => [
            // Leave empty to reuse the credentials of the community_store_payrexx package
            // (community_store_payrexx.instanceName / .secret). Older sites configured
            // payrexx.instance / payrexx.apikey, which are still honoured.
            'instance' => '',
            'apikey' => '',
            'currency' => 'CHF',
            // How long (minutes) a created payment gateway link is reused before a new one is requested
            'gateway_reuse_minutes' => 20,
        ],
        'paypal' => [
            // The PayPal webhook is only accepted when enabled AND a webhook id is configured:
            // every notification is verified against PayPal before anything is booked.
            'enabled' => false,
            'environment' => 'sandbox',
            'client_id' => '',
            'client_secret' => '',
            'sandbox_client_id' => '',
            'sandbox_client_secret' => '',
            'webhook_id' => '',
        ],
        'bank' => [
            'iban' => '',
            'name' => '',
            'pc' => '',
            'account_name' => '',
        ],
    ],
    // Define a set of Groups that is Usable as Filter in the Credit Manager Backend
    'relevant_groups' => [
        //'group-id' => 'Filter name in CM overview'
    ],
    // Topic tree that holds the categories for transactions (set automatically on install)
    'categories_topic' => 0,
    'product_categories_topic' => 0,
    // Lowest balance a POS checkout may leave on an account; null = no limit
    'negative_balance_limit' => null,
    'currency_symbol' => '<i class="fa fa-dollar"></i>',
    // The event the POS pages sell for. Only used when Application\Turicane\CurrentLan is not available.
    'event' => [
        'page_id' => 0,
        'title' => '',
        'participant_group_id' => 0,
    ],
    // Theme template used by the full-screen pages (POS, order management); it must output $innerContent
    'fullscreen_template' => 'blank.php',
    // Community Store product type shown on the POS pages
    'pos_product_type_id' => 3,
];
