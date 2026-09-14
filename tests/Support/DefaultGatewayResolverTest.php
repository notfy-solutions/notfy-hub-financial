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

it('routes by payment type when the routing table defines one', function () {
    $asaas = fakeGatewayClass('asaas-gateway-stub-3');
    $stripe = fakeGatewayClass('stripe-gateway-stub');

    $resolver = new DefaultGatewayResolver(
        gateways: ['asaas' => ['class' => $asaas], 'stripe' => ['class' => $stripe]],
        routing: ['BR' => ['default' => 'stripe', 'pix' => 'asaas']],
    );

    $gateway = $resolver->resolve(DefaultGatewayContext::make(country: 'BR', paymentType: 'pix'));

    expect(app($asaas))->toBe($gateway);
});

it('falls back to default when the payment type has no specific route', function () {
    $stripe = fakeGatewayClass('stripe-gateway-stub-2');

    $resolver = new DefaultGatewayResolver(
        gateways: ['stripe' => ['class' => $stripe]],
        routing: ['BR' => ['default' => 'stripe']],
    );

    $gateway = $resolver->resolve(DefaultGatewayContext::make(country: 'BR', paymentType: 'boleto'));

    expect(app($stripe))->toBe($gateway);
});

it('returns every configured gateway with the resolved one first', function () {
    $asaas = fakeGatewayClass('asaas-gateway-stub-4');
    $stripe = fakeGatewayClass('stripe-gateway-stub-3');

    $resolver = new DefaultGatewayResolver(
        gateways: ['asaas' => ['class' => $asaas], 'stripe' => ['class' => $stripe]],
        routing: ['BR' => ['default' => 'asaas'], '*' => ['default' => 'stripe']],
    );

    $all = $resolver->getAllAvailable(DefaultGatewayContext::make(country: 'BR'));

    expect($all)->toHaveCount(2)
        ->and($all[0])->toBe(app($asaas))
        ->and($all[1])->toBe(app($stripe));
});

it('falls back to the first configured gateway when the route has no default', function () {
    $asaas = fakeGatewayClass('asaas-gateway-stub-6');

    $resolver = new DefaultGatewayResolver(
        gateways: ['asaas' => ['class' => $asaas]],
        routing: ['BR' => ['pix' => 'asaas']],
    );

    $gateway = $resolver->resolve(DefaultGatewayContext::make(country: 'BR', paymentType: 'boleto'));

    expect(app($asaas))->toBe($gateway);
});

it('throws when there is no default route and no gateway configured at all', function () {
    $resolver = new DefaultGatewayResolver(gateways: [], routing: ['BR' => ['pix' => 'asaas']]);

    $resolver->resolve(DefaultGatewayContext::make(country: 'BR', paymentType: 'boleto'));
})->throws(InvalidArgumentException::class, 'Nenhum gateway configurado.');

it('does not duplicate the primary gateway when listing all available gateways', function () {
    $asaas = fakeGatewayClass('asaas-gateway-stub-5');

    $resolver = new DefaultGatewayResolver(
        gateways: ['asaas' => ['class' => $asaas]],
        routing: ['*' => ['default' => 'asaas']],
    );

    $all = $resolver->getAllAvailable(DefaultGatewayContext::make());

    expect($all)->toHaveCount(1);
});
