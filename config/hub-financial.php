<?php

declare(strict_types=1);

use Notfy\HubFinancial\Gateways\Ailos\AilosPlugin;
use Notfy\HubFinancial\Gateways\Asaas\AsaasPlugin;
use Notfy\HubFinancial\Gateways\Stripe\StripePlugin;

return [
    /*
    |--------------------------------------------------------------------------
    | Plugins de gateway
    |--------------------------------------------------------------------------
    | Classes que implementam GatewayPlugin. Um provedor novo (outro país) entra
    | aqui; o ServiceProvider do pacote não precisa mudar.
    */
    'plugins' => [
        AsaasPlugin::class,
        AilosPlugin::class,
        StripePlugin::class,
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

    /*
    |--------------------------------------------------------------------------
    | Resiliência
    |--------------------------------------------------------------------------
    | Usada por ResilientGatewayFactory::wrap(). Leituras fazem retry em falha
    | transitória; escritas só em 429 (evita cobrança duplicada). O circuit
    | breaker abre após N falhas transitórias seguidas e fecha após o cooldown.
    */
    'resilience' => [
        'max_attempts' => 3,
        'base_delay_ms' => 200,
        'max_delay_ms' => 5000,
        'failure_threshold' => 5,
        'cooldown_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    | dedupe_ttl: por quanto tempo (s) lembrar eventIds já processados.
    */
    'webhooks' => [
        'dedupe_ttl' => 604800,
    ],
];
