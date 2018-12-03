<?php

return [
    'allowed_events' => [
        'subscription_canceled',
        'subscription_created',
        'subscription_reactivated',
        'charge_created',
        'charge_refunded',
        'bill_created',
        'bill_canceled'
    ],
    'env' => 'testing',
    'model' => 'App\User',
    'credit_card_label' => 'credit_card'
];
