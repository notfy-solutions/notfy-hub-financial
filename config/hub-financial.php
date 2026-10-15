<?php

declare(strict_types=1);

use Notfy\HubFinancial\Gateways\Asaas\AsaasBillingGateway;
use Notfy\HubFinancial\Gateways\Stripe\StripeBillingGateway;

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
            'environment' => env('ASAAS_ENVIRONMENT', 'sandbox'),
            'webhook_auth_token' => env('ASAAS_WEBHOOK_TOKEN'),
        ],
        'stripe' => [
            'class' => StripeBillingGateway::class,
            'secret_key' => env('STRIPE_SECRET_KEY'),
            // Deixe em branco em produção: o SDK já usa a API oficial do
            // Stripe por padrão. Só defina para apontar pra um servidor de
            // testes (ex.: stripe-mock) — o SDK anexa "/v1" sozinho, então
            // não inclua o sufixo aqui.
            'base_url' => env('STRIPE_API_URL'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'default_currency' => env('STRIPE_DEFAULT_CURRENCY', 'usd'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Roteamento por país/tipo de pagamento
    |--------------------------------------------------------------------------
    | Brasil usa Asaas (pix/boleto locais); qualquer outro país cai no
    | Stripe (cartão internacional) por padrão.
    */
    'routing' => [
        'BR' => ['default' => 'asaas'],
        '*' => ['default' => 'stripe'],
    ],
];
