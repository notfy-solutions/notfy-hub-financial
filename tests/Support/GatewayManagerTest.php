<?php

declare(strict_types=1);

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Gateways\Fake\FakeGateway;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Support\GatewayManager;

function stubBillingGateway(): BillingGateway
{
    return new FakeGateway;
}

it('extend() registers a driver and forAccount() instantiates it', function () {
    $manager = new GatewayManager;
    $driver = stubBillingGateway();

    $manager->extend('fake', fn (GatewayAccount $account) => $driver);

    $account = new GatewayAccount('fake', new ApiKey('key'));

    expect($manager->forAccount($account))->toBe($driver);
});

it('forAccount() passes the GatewayAccount to the factory', function () {
    $manager = new GatewayManager;
    $received = null;

    $manager->extend('fake', function (GatewayAccount $account) use (&$received): BillingGateway {
        $received = $account;

        return stubBillingGateway();
    });

    $account = new GatewayAccount('fake', new ApiKey('key123'), webhookSecret: 'secret', options: ['base_url' => 'https://x']);
    $manager->forAccount($account);

    expect($received)->toBe($account)
        ->and($received->provider)->toBe('fake')
        ->and($received->webhookSecret)->toBe('secret')
        ->and($received->options['base_url'])->toBe('https://x');
});

it('forAccount() throws when the provider is not registered', function () {
    $manager = new GatewayManager;
    $account = new GatewayAccount('unregistered', new ApiKey('key'));

    $manager->forAccount($account);
})->throws(InvalidArgumentException::class);

it('hasDriver() returns true for registered providers and false for unknown ones', function () {
    $manager = new GatewayManager;
    $manager->extend('registered', fn (GatewayAccount $a) => stubBillingGateway());

    expect($manager->hasDriver('registered'))->toBeTrue()
        ->and($manager->hasDriver('unknown'))->toBeFalse();
});

it('extend() overwrites an existing driver registration', function () {
    $manager = new GatewayManager;
    $first = stubBillingGateway();
    $second = stubBillingGateway();

    $manager->extend('fake', fn () => $first);
    $manager->extend('fake', fn () => $second);

    $account = new GatewayAccount('fake', new ApiKey('key'));

    expect($manager->forAccount($account))->toBe($second);
});

it('lists the registered providers', function () {
    $manager = new GatewayManager;
    $manager->extend('asaas', fn (): BillingGateway => stubBillingGateway());
    $manager->extend('stripe', fn (): BillingGateway => stubBillingGateway());

    expect($manager->providers())->toBe(['asaas', 'stripe']);
});
