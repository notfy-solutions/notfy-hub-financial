<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Gateways\Asaas\AsaasBillingGateway;
use Notfy\HubFinancial\Gateways\Asaas\AsaasHttpClient;
use Notfy\HubFinancial\Gateways\Stripe\StripeBillingGateway;
use Notfy\HubFinancial\HubFinancialServiceProvider;
use Notfy\HubFinancial\Support\GatewayResolver;
use Notfy\HubFinancial\Webhooks\AsaasWebhookParser;
use Notfy\HubFinancial\Webhooks\StripeWebhookParser;
use Stripe\StripeClient;

it('merges the package config', function () {
    expect(config('hub-financial.routing.BR.default'))->toBe('asaas')
        ->and(config('hub-financial.routing.*.default'))->toBe('stripe');
});

it('resolves the Asaas http client and gateway from config', function () {
    expect(app(AsaasHttpClient::class))->toBeInstanceOf(AsaasHttpClient::class)
        ->and(app(AsaasBillingGateway::class))->toBeInstanceOf(AsaasBillingGateway::class);
});

it('resolves the Stripe client and gateway from config', function () {
    expect(app(StripeClient::class))->toBeInstanceOf(StripeClient::class)
        ->and(app(StripeClient::class)->getApiKey())->toBe('sk_test_123')
        ->and(app(StripeBillingGateway::class))->toBeInstanceOf(StripeBillingGateway::class);
});

it('applies a custom Stripe api_base when one is configured', function () {
    config(['hub-financial.gateways.stripe.base_url' => 'https://stripe-mock.local']);
    app()->forgetInstance(StripeClient::class);

    expect(app(StripeClient::class)->getApiBase())->toBe('https://stripe-mock.local');
});

it('resolves the gateway resolver as a singleton', function () {
    expect(app(GatewayResolver::class))->toBe(app(GatewayResolver::class));
});

it('resolves the default BillingGateway binding via the resolver', function () {
    expect(app(BillingGateway::class))->toBeInstanceOf(AsaasBillingGateway::class);
});

it('resolves the webhook parsers', function () {
    expect(app(AsaasWebhookParser::class))->toBeInstanceOf(AsaasWebhookParser::class)
        ->and(app(StripeWebhookParser::class))->toBeInstanceOf(StripeWebhookParser::class);
});

it('registers the config file for publishing', function () {
    $paths = ServiceProvider::pathsToPublish(HubFinancialServiceProvider::class, 'hub-financial-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(config_path('hub-financial.php'));
});
