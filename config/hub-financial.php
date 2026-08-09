<?php

declare(strict_types=1);

use Notfy\HubFinancial\Gateways\Asaas\AsaasBillingGateway;

return [
    /*
    |--------------------------------------------------------------------------
    | Gateway padrão
    |--------------------------------------------------------------------------
    */
    'default' => env('HUB_FINANCIAL_GATEWAY', 'asaas'),

    /*
    |--------------------------------------------------------------------------
    | Gateways disponíveis
    |--------------------------------------------------------------------------
    | Cada entrada precisa de uma 'class' que implemente BillingGateway.
    */
    'gateways' => [
        'asaas' => [
            'class' => AsaasBillingGateway::class,
            'api_key' => env('ASAAS_API_KEY'),
            'base_url' => env('ASAAS_API_URL', 'https://api-sandbox.asaas.com/v3'),
            'webhook_auth_token' => env('ASAAS_WEBHOOK_TOKEN'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Roteamento por país/tipo de pagamento
    |--------------------------------------------------------------------------
    */
    'routing' => [
        'BR' => ['default' => 'asaas'],
        '*' => ['default' => 'asaas'],
    ],
];
