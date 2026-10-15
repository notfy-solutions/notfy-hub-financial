<?php

declare(strict_types=1);

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\DTO\CustomerResult;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Support\GatewayManager;

function stubBillingGateway(): BillingGateway
{
    return new class implements BillingGateway
    {
        public function createCustomer(CustomerInput $input): CustomerResult
        {
            return new CustomerResult('fake');
        }

        public function updateCustomer(string $externalId, CustomerInput $input): CustomerResult
        {
            return new CustomerResult('fake');
        }

        public function deleteCustomer(string $externalId): bool
        {
            return true;
        }

        public function createCharge(ChargeInput $input): ChargeResult
        {
            return new ChargeResult('fake', 'PENDING');
        }

        public function fetchCharge(string $externalId): ChargeResult
        {
            return new ChargeResult('fake', 'PENDING');
        }

        public function refundCharge(string $externalId, ?int $amountCents = null): bool
        {
            return true;
        }

        public function cancelCharge(string $externalId): bool
        {
            return true;
        }

        public function createCheckoutSession(array $params): array
        {
            return ['id' => null, 'url' => null, 'raw' => []];
        }

        public function createBillingPortalSession(string $customerExternalId, string $returnUrl): array
        {
            return ['url' => null, 'raw' => []];
        }
    };
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
