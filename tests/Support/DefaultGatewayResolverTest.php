<?php

declare(strict_types=1);

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\DTO\CustomerResult;
use Notfy\HubFinancial\Support\DefaultGatewayContext;
use Notfy\HubFinancial\Support\DefaultGatewayResolver;

function fakeGatewayClass(string $name): string
{
    $class = new class implements BillingGateway
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

    app()->instance($name, $class);

    return $name;
}

it('resolves the default gateway for the routing table', function () {
    $asaas = fakeGatewayClass('asaas-gateway-stub');

    $resolver = new DefaultGatewayResolver(
        gateways: ['asaas' => ['class' => $asaas]],
        routing: ['BR' => ['default' => 'asaas'], '*' => ['default' => 'asaas']],
    );

    $gateway = $resolver->resolve(DefaultGatewayContext::make(country: 'BR'));

    expect($gateway)->toBeInstanceOf(BillingGateway::class);
});

it('prefers the client preferred gateway over routing', function () {
    $asaas = fakeGatewayClass('asaas-gateway-stub-2');
    $other = fakeGatewayClass('other-gateway-stub');

    $resolver = new DefaultGatewayResolver(
        gateways: [
            'asaas' => ['class' => $asaas],
            'other' => ['class' => $other],
        ],
        routing: ['*' => ['default' => 'asaas']],
    );

    $gateway = $resolver->resolve(DefaultGatewayContext::make(preferredGateway: 'other'));

    expect(app($other))->toBe($gateway);
});

it('throws when the requested gateway is not configured', function () {
    $resolver = new DefaultGatewayResolver(gateways: [], routing: ['*' => ['default' => 'missing']]);

    $resolver->resolve(DefaultGatewayContext::make());
})->throws(InvalidArgumentException::class);
