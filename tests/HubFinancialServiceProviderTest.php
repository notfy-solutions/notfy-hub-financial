<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Gateways\Asaas\AsaasBillingGateway;
use Notfy\HubFinancial\Gateways\Asaas\AsaasEnvironment;
use Notfy\HubFinancial\Gateways\Asaas\AsaasHttpClient;
use Notfy\HubFinancial\Gateways\Asaas\AsaasPlugin;
use Notfy\HubFinancial\Gateways\Stripe\StripeBillingGateway;
use Notfy\HubFinancial\Gateways\Stripe\StripePlugin;
use Notfy\HubFinancial\HubFinancialServiceProvider;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Support\GatewayManager;
use Notfy\HubFinancial\Support\GatewayResolver;
use Notfy\HubFinancial\Webhooks\AsaasWebhookParser;
use Notfy\HubFinancial\Webhooks\StripeWebhookParser;
use Stripe\StripeClient;

it('merges the package config', function () {
    expect(config('hub-financial.routing.BR.default'))->toBe('asaas')
        ->and(config('hub-financial.routing.*.default'))->toBe('stripe');
});

it('does not read any credential from config or env', function () {
    expect(config('hub-financial'))->not->toHaveKeys(['gateways', 'default'])
        ->and(app()->bound(StripeClient::class))->toBeFalse()
        ->and(app()->bound(AsaasHttpClient::class))->toBeFalse();
});

it('resolves the gateway resolver as a singleton', function () {
    expect(app(GatewayResolver::class))->toBe(app(GatewayResolver::class));
});

it('resolves the webhook parsers through the plugins', function () {
    expect((new AsaasPlugin)->webhookHandler())->toBeInstanceOf(AsaasWebhookParser::class)
        ->and((new StripePlugin)->webhookHandler())->toBeInstanceOf(StripeWebhookParser::class);
});

it('registers the config file for publishing', function () {
    $paths = ServiceProvider::pathsToPublish(HubFinancialServiceProvider::class, 'hub-financial-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(config_path('hub-financial.php'));
});

it('resolves the GatewayManager as a singleton', function () {
    expect(app(GatewayManager::class))->toBeInstanceOf(GatewayManager::class)
        ->and(app(GatewayManager::class))->toBe(app(GatewayManager::class));
});

it('GatewayManager builds an AsaasBillingGateway from a GatewayAccount', function () {
    $account = new GatewayAccount('asaas', new ApiKey('test-key'));
    $gateway = app(GatewayManager::class)->forAccount($account);

    expect($gateway)->toBeInstanceOf(AsaasBillingGateway::class);
});

it('GatewayManager builds a StripeBillingGateway from a GatewayAccount', function () {
    $account = new GatewayAccount('stripe', new ApiKey('sk_test_123'));
    $gateway = app(GatewayManager::class)->forAccount($account);

    expect($gateway)->toBeInstanceOf(StripeBillingGateway::class);
});

it('GatewayManager builds an Asaas gateway for the requested environment', function () {
    $account = new GatewayAccount(
        provider: 'asaas',
        credentials: new ApiKey('key'),
        options: ['environment' => 'production'],
    );

    expect(app(GatewayManager::class)->forAccount($account))->toBeInstanceOf(AsaasBillingGateway::class);
});

it('AsaasEnvironment resolves the base URL and falls back to sandbox', function () {
    expect(AsaasEnvironment::Production->baseUrl())->toBe('https://api.asaas.com/v3')
        ->and(AsaasEnvironment::Sandbox->baseUrl())->toBe('https://api-sandbox.asaas.com/v3')
        ->and(AsaasEnvironment::fromConfig('production'))->toBe(AsaasEnvironment::Production)
        ->and(AsaasEnvironment::fromConfig('qualquer-coisa'))->toBe(AsaasEnvironment::Sandbox)
        ->and(AsaasEnvironment::fromConfig(null))->toBe(AsaasEnvironment::Sandbox);
});

it('GatewayManager uses the options base_url for Stripe when provided', function () {
    $account = new GatewayAccount(
        provider: 'stripe',
        credentials: new ApiKey('sk_test_123'),
        options: ['base_url' => 'https://stripe-mock.local', 'default_currency' => 'brl'],
    );

    expect(app(GatewayManager::class)->forAccount($account))->toBeInstanceOf(StripeBillingGateway::class);
});
