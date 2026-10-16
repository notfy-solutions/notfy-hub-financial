<?php

declare(strict_types=1);

use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\Gateways\Asaas\AsaasBillingGateway;
use Notfy\HubFinancial\Gateways\Asaas\AsaasHttpClient;
use Notfy\HubFinancial\Gateways\Fake\FakeGateway;
use Notfy\HubFinancial\Gateways\Stripe\StripeBillingGateway;
use Stripe\StripeClient;

it('asaas declares Brazil, BRL and the local payment methods', function () {
    $gateway = new AsaasBillingGateway(new AsaasHttpClient('key', 'https://api-sandbox.asaas.com/v3'));

    expect($gateway->supportedCountries())->toBe(['BR'])
        ->and($gateway->supportedCurrencies())->toBe(['BRL'])
        ->and($gateway->supportedPaymentMethods())->toContain(PaymentMethod::Boleto, PaymentMethod::Pix, PaymentMethod::Card)
        ->and($gateway->supportedPaymentMethods())->not->toContain(PaymentMethod::Ach)
        ->and($gateway->capabilities())->toContain(Capability::BankSlip, Capability::Pix, Capability::Subscription)
        ->and($gateway->capabilities())->not->toContain(Capability::MultiCurrency);
});

it('stripe declares worldwide coverage, any currency and the international methods', function () {
    $gateway = new StripeBillingGateway(new StripeClient('sk_test_x'));

    expect($gateway->supportedCountries())->toBe(['*'])
        ->and($gateway->supportedCurrencies())->toBe(['*'])
        ->and($gateway->supportedPaymentMethods())->toContain(PaymentMethod::Card, PaymentMethod::Ach, PaymentMethod::SepaDebit)
        ->and($gateway->capabilities())->toContain(Capability::MultiCurrency, Capability::Ach, Capability::SepaDebit);
});

it('the fake gateway serves every country, currency, method and capability', function () {
    $gateway = new FakeGateway;

    expect($gateway->supportedCountries())->toBe(['*'])
        ->and($gateway->supportedCurrencies())->toBe(['*'])
        ->and($gateway->supportedPaymentMethods())->toBe(PaymentMethod::cases())
        ->and($gateway->capabilities())->toBe(Capability::cases());
});
