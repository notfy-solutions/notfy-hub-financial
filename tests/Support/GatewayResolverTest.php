<?php

declare(strict_types=1);

use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\Exceptions\UnsupportedGatewayRequestException;
use Notfy\HubFinancial\Gateways\Asaas\AsaasBillingGateway;
use Notfy\HubFinancial\Gateways\Stripe\StripeBillingGateway;
use Notfy\HubFinancial\Support\DefaultGatewayContext;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Support\GatewayResolver;

function tenantAccounts(): array
{
    return [
        new GatewayAccount('stripe', new ApiKey('sk_tenant')),
        new GatewayAccount('asaas', new ApiKey('asaas_tenant')),
    ];
}

function accountResolver(): GatewayResolver
{
    return app(GatewayResolver::class);
}

it('routes by country using only the credentials it was given', function () {
    expect(accountResolver()->resolve(tenantAccounts(), DefaultGatewayContext::make(country: 'BR')))
        ->toBeInstanceOf(AsaasBillingGateway::class)
        ->and(accountResolver()->resolve(tenantAccounts(), DefaultGatewayContext::make(country: 'DE', currency: 'EUR')))
        ->toBeInstanceOf(StripeBillingGateway::class);
});

it('does not need any env/config credentials', function () {
    config(['hub-financial.gateways' => []]);

    expect(accountResolver()->resolve(tenantAccounts(), DefaultGatewayContext::make(country: 'US')))
        ->toBeInstanceOf(StripeBillingGateway::class);
});

it('falls back to any given account that serves the request when the routed one is missing', function () {
    $onlyStripe = [new GatewayAccount('stripe', new ApiKey('sk'))];

    expect(accountResolver()->resolve($onlyStripe, DefaultGatewayContext::make(country: 'BR', currency: 'USD')))
        ->toBeInstanceOf(StripeBillingGateway::class);
});

it('skips an account that cannot serve the request and uses the next', function () {
    $gateway = accountResolver()->resolve(
        tenantAccounts(),
        DefaultGatewayContext::make(country: 'BR'),
        PaymentMethod::SepaDebit,
    );

    expect($gateway)->toBeInstanceOf(StripeBillingGateway::class);
});

it('honours the preferred gateway and the payment type routing', function () {
    $preferred = DefaultGatewayContext::make(preferredGateway: 'stripe', country: 'BR');
    expect(accountResolver()->resolve(tenantAccounts(), $preferred))->toBeInstanceOf(StripeBillingGateway::class);

    config(['hub-financial.routing' => ['BR' => ['default' => 'asaas', 'card' => 'stripe']]]);
    $byType = DefaultGatewayContext::make(country: 'BR', paymentType: 'card');
    expect(app()->make(GatewayResolver::class)->resolve(tenantAccounts(), $byType))
        ->toBeInstanceOf(AsaasBillingGateway::class);
});

it('refuses when no given account serves the request', function () {
    accountResolver()->resolve(
        [new GatewayAccount('asaas', new ApiKey('k'))],
        DefaultGatewayContext::make(country: 'DE'),
    );
})->throws(UnsupportedGatewayRequestException::class);

it('refuses a capability no account offers', function () {
    accountResolver()->resolve(
        [new GatewayAccount('asaas', new ApiKey('k'))],
        DefaultGatewayContext::make(country: 'BR'),
        null,
        Capability::Ach,
    );
})->throws(UnsupportedGatewayRequestException::class);

it('requires at least one account', function () {
    accountResolver()->resolve([]);
})->throws(InvalidArgumentException::class);

it('works with the default context', function () {
    expect(accountResolver()->resolve([new GatewayAccount('asaas', new ApiKey('k'))]))
        ->toBeInstanceOf(AsaasBillingGateway::class);
});

it('supportsCountry() asks the given accounts', function () {
    $onlyAsaas = [new GatewayAccount('asaas', new ApiKey('k'))];

    expect(accountResolver()->supportsCountry($onlyAsaas, 'BR'))->toBeTrue()
        ->and(accountResolver()->supportsCountry($onlyAsaas, 'DE'))->toBeFalse()
        ->and(accountResolver()->supportsCountry(tenantAccounts(), 'DE'))->toBeTrue();
});

it('available() lists every account that serves the context, routed one first', function () {
    $gateways = accountResolver()->available(tenantAccounts(), DefaultGatewayContext::make(country: 'BR', currency: 'BRL'));

    expect($gateways)->toHaveCount(2)
        ->and($gateways[0])->toBeInstanceOf(AsaasBillingGateway::class);

    $gateways = accountResolver()->available(tenantAccounts(), DefaultGatewayContext::make(country: 'DE', currency: 'EUR'));

    expect($gateways)->toHaveCount(1)->and($gateways[0])->toBeInstanceOf(StripeBillingGateway::class);
});
