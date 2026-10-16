<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Domain\Enums\ChargeStatus;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\Events\CircuitBreakerOpened;
use Notfy\HubFinancial\Events\GatewayCallFailed;
use Notfy\HubFinancial\Exceptions\AsaasApiException;
use Notfy\HubFinancial\Exceptions\GatewayUnavailableException;
use Notfy\HubFinancial\Gateways\Fake\FakeGateway;
use Notfy\HubFinancial\Resilience\CircuitBreaker;
use Notfy\HubFinancial\Resilience\ResilientGateway;
use Notfy\HubFinancial\Resilience\ResilientGatewayFactory;
use Notfy\HubFinancial\Resilience\RetryPolicy;

/** Gateway que falha N vezes com o status dado e depois devolve sucesso; $state->calls conta as chamadas. */
function flaky(int $failures, int $status): BillingGateway
{
    $state = new stdClass;
    $state->calls = 0;
    $tick = function () use ($state, $failures, $status): ChargeResult {
        $state->calls++;
        if ($state->calls <= $failures) {
            throw new AsaasApiException('boom', $status);
        }

        return new ChargeResult('ch_1', ChargeStatus::Pending);
    };

    $mock = Mockery::mock(BillingGateway::class);
    $mock->shouldReceive('fetchCharge')->andReturnUsing($tick);
    $mock->shouldReceive('createCharge')->andReturnUsing($tick);
    $mock->state = $state;

    return $mock;
}

function resilient(BillingGateway $inner, int $threshold = 5, array &$events = [], array &$sleeps = []): ResilientGateway
{
    $dispatcher = new Dispatcher;
    $dispatcher->listen(GatewayCallFailed::class, function ($e) use (&$events) {
        $events[] = $e;
    });
    $dispatcher->listen(CircuitBreakerOpened::class, function ($e) use (&$events) {
        $events[] = $e;
    });

    return new ResilientGateway(
        $inner,
        'fake',
        new RetryPolicy(3, 100, 1000),
        new CircuitBreaker(new Repository(new ArrayStore), $threshold, 30),
        $dispatcher,
        function (int $ms) use (&$sleeps) {
            $sleeps[] = $ms;
        },
    );
}

function resilienceChargeInput(): ChargeInput
{
    return new ChargeInput('cus_1', 1000, null, PaymentMethod::Card, 'x', 'ref-1');
}

it('retries reads on transient failures and then succeeds', function () {
    $inner = flaky(2, 503);
    $sleeps = [];
    $events = [];

    $result = resilient($inner, 5, $events, $sleeps)->fetchCharge('ch_1');

    expect($result->externalId)->toBe('ch_1')
        ->and($inner->state->calls)->toBe(3)
        ->and($sleeps)->toHaveCount(2)
        ->and($events)->toHaveCount(2);
});

it('gives up after max attempts and rethrows', function () {
    $inner = flaky(10, 500);

    resilient($inner)->fetchCharge('ch_1');
})->throws(AsaasApiException::class);

it('does not retry writes on 5xx (avoids double charge)', function () {
    $inner = flaky(1, 500);

    try {
        resilient($inner)->createCharge(resilienceChargeInput());
    } catch (AsaasApiException) {
    }

    expect($inner->state->calls)->toBe(1);
});

it('retries writes on 429 because the provider did not process them', function () {
    $inner = flaky(1, 429);

    $result = resilient($inner)->createCharge(resilienceChargeInput());

    expect($result->externalId)->toBe('ch_1')->and($inner->state->calls)->toBe(2);
});

it('never retries business errors (4xx)', function () {
    $inner = flaky(1, 422);

    try {
        resilient($inner)->fetchCharge('x');
    } catch (AsaasApiException) {
    }

    expect($inner->state->calls)->toBe(1);
});

it('opens the circuit after the threshold and fails fast', function () {
    $inner = flaky(100, 500);
    $events = [];
    $gateway = resilient($inner, 3, $events);

    foreach ([1, 2] as $_) {
        try {
            $gateway->createCharge(resilienceChargeInput());
        } catch (AsaasApiException) {
        }
    }
    try {
        $gateway->createCharge(resilienceChargeInput());
    } catch (AsaasApiException) {
    }

    $callsBefore = $inner->state->calls;

    expect(fn () => $gateway->createCharge(resilienceChargeInput()))->toThrow(GatewayUnavailableException::class)
        ->and($inner->state->calls)->toBe($callsBefore)
        ->and(collect($events)->contains(fn ($e) => $e instanceof CircuitBreakerOpened))->toBeTrue();
});

it('delegates the DescribesGateway methods and the remaining operations', function () {
    $gateway = resilient(new FakeGateway);

    expect($gateway->capabilities())->not->toBeEmpty()
        ->and($gateway->supportedCountries())->not->toBeEmpty()
        ->and($gateway->supportedCurrencies())->not->toBeEmpty()
        ->and($gateway->supportedPaymentMethods())->not->toBeEmpty();

    $customer = $gateway->createCustomer(new CustomerInput('A', '1'));
    $gateway->updateCustomer($customer->externalId, new CustomerInput('B', '1'));
    $charge = $gateway->createCharge(resilienceChargeInput());

    expect($gateway->fetchCharge($charge->externalId)->externalId)->toBe($charge->externalId);
    $gateway->createCheckoutSession([]);
    $gateway->createBillingPortalSession($customer->externalId, 'https://x');
    $gateway->refundCharge($charge->externalId);
    $gateway->cancelCharge($charge->externalId);
    $gateway->deleteCustomer($customer->externalId);
});

it('factory wraps any gateway', function () {
    $factory = new ResilientGatewayFactory(new RetryPolicy, new CircuitBreaker(new Repository(new ArrayStore)));

    expect($factory->wrap(new FakeGateway, 'fake:t1'))->toBeInstanceOf(ResilientGateway::class);
});

it('RetryPolicy treats non-gateway exceptions as non transient', function () {
    expect(RetryPolicy::isTransient(new RuntimeException))->toBeFalse()
        ->and(RetryPolicy::isTransient(new ConnectionException('x')))->toBeTrue();
});

it('works with the default sleeper', function () {
    $gateway = new ResilientGateway(
        new FakeGateway,
        'fake',
        new RetryPolicy,
        new CircuitBreaker(new Repository(new ArrayStore)),
    );

    expect($gateway->capabilities())->not->toBeEmpty();
});
