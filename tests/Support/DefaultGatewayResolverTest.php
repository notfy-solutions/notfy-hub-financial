<?php

declare(strict_types=1);

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Gateways\Fake\FakeGateway;
use Notfy\HubFinancial\Support\DefaultGatewayContext;
use Notfy\HubFinancial\Support\DefaultGatewayResolver;

function fakeGatewayClass(string $name, ?FakeGateway $gateway = null): string
{
    app()->instance($name, $gateway ?? new FakeGateway);

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

// ─── Internacionalização: país, moeda, meio de pagamento e capacidade ────────

use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\Exceptions\UnsupportedGatewayRequestException;
use Notfy\HubFinancial\Gateways\Asaas\AsaasBillingGateway;
use Notfy\HubFinancial\Gateways\Asaas\AsaasHttpClient;

function brazilOnlyGateway(string $name): string
{
    app()->instance($name, new AsaasBillingGateway(new AsaasHttpClient('key', 'https://api-sandbox.asaas.com/v3')));

    return $name;
}

it('resolveFor returns a gateway that serves the country, currency, method and capability', function () {
    $br = brazilOnlyGateway('asaas-br');

    $resolver = new DefaultGatewayResolver(
        gateways: ['asaas' => ['class' => $br]],
        routing: ['BR' => ['default' => 'asaas']],
    );

    $gateway = $resolver->resolveFor(
        DefaultGatewayContext::make(country: 'BR', currency: 'brl'),
        PaymentMethod::Pix,
        Capability::Pix,
    );

    expect($gateway)->toBe(app($br));
});

it('resolveFor uses the default context and no extra requirements when none are given', function () {
    $br = brazilOnlyGateway('asaas-br-default');

    $resolver = new DefaultGatewayResolver(gateways: ['asaas' => ['class' => $br]], routing: ['BR' => ['default' => 'asaas']]);

    expect($resolver->resolveFor())->toBe(app($br));
});

it('resolveFor refuses a country the resolved gateway does not serve', function () {
    $br = brazilOnlyGateway('asaas-br-country');

    $resolver = new DefaultGatewayResolver(gateways: ['asaas' => ['class' => $br]], routing: ['*' => ['default' => 'asaas']]);

    $resolver->resolveFor(DefaultGatewayContext::make(country: 'US'));
})->throws(UnsupportedGatewayRequestException::class, "não atende o país 'US'");

it('resolveFor refuses a currency the gateway does not charge in', function () {
    $br = brazilOnlyGateway('asaas-br-currency');

    $resolver = new DefaultGatewayResolver(gateways: ['asaas' => ['class' => $br]], routing: ['BR' => ['default' => 'asaas']]);

    $resolver->resolveFor(DefaultGatewayContext::make(country: 'BR', currency: 'usd'));
})->throws(UnsupportedGatewayRequestException::class, "não cobra na moeda 'USD'");

it('resolveFor refuses a payment method the gateway does not accept', function () {
    $br = brazilOnlyGateway('asaas-br-method');

    $resolver = new DefaultGatewayResolver(gateways: ['asaas' => ['class' => $br]], routing: ['BR' => ['default' => 'asaas']]);

    $resolver->resolveFor(DefaultGatewayContext::make(country: 'BR'), PaymentMethod::Ach);
})->throws(UnsupportedGatewayRequestException::class, "não aceita o meio de pagamento 'ACH'");

it('resolveFor refuses a capability the gateway does not have', function () {
    $br = brazilOnlyGateway('asaas-br-capability');

    $resolver = new DefaultGatewayResolver(gateways: ['asaas' => ['class' => $br]], routing: ['BR' => ['default' => 'asaas']]);

    $resolver->resolveFor(DefaultGatewayContext::make(country: 'BR'), null, Capability::MultiCurrency);
})->throws(UnsupportedGatewayRequestException::class, "não tem a capacidade 'multi_currency'");

it('getAllAvailable only lists fallback gateways that serve the country', function () {
    $br = brazilOnlyGateway('asaas-only-br');
    $intl = fakeGatewayClass('stripe-like');

    $resolver = new DefaultGatewayResolver(
        gateways: ['asaas' => ['class' => $br], 'stripe' => ['class' => $intl]],
        routing: ['US' => ['default' => 'stripe'], '*' => ['default' => 'stripe']],
    );

    $available = $resolver->getAllAvailable(DefaultGatewayContext::make(country: 'US'));

    expect($available)->toHaveCount(1)->and($available[0])->toBe(app($intl));
});

it('supportsCountry is true only when a configured gateway is routed for the country', function () {
    $br = brazilOnlyGateway('asaas-support');

    $resolver = new DefaultGatewayResolver(
        gateways: ['asaas' => ['class' => $br]],
        routing: ['BR' => ['default' => 'asaas'], 'PT' => ['default' => 'missing']],
    );

    expect($resolver->supportsCountry('BR'))->toBeTrue()
        ->and($resolver->supportsCountry('PT'))->toBeFalse();
});

it('supportsCountry falls back to the wildcard route and never instantiates the gateway', function () {
    $resolver = new DefaultGatewayResolver(
        gateways: ['stripe' => ['class' => 'class-that-would-fail-if-instantiated']],
        routing: ['*' => ['default' => 'stripe']],
    );

    expect($resolver->supportsCountry('PT'))->toBeTrue();
});

it('supportsCountry uses the first configured gateway when there is no route at all', function () {
    $resolver = new DefaultGatewayResolver(
        gateways: ['only' => ['class' => 'x']],
        routing: [],
    );

    expect($resolver->supportsCountry('BR'))->toBeTrue();
});

it('supportsCountry is false without any configured gateway', function () {
    expect((new DefaultGatewayResolver(gateways: [], routing: []))->supportsCountry('BR'))->toBeFalse();
});
