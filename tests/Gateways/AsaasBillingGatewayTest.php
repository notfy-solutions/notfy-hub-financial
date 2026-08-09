<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\Gateways\Asaas\AsaasBillingGateway;
use Notfy\HubFinancial\Gateways\Asaas\AsaasHttpClient;

function gateway(): AsaasBillingGateway
{
    return new AsaasBillingGateway(new AsaasHttpClient('test-key', 'https://api-sandbox.asaas.com/v3'));
}

it('creates a customer', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/customers' => Http::response(['id' => 'cus_123', 'name' => 'Fulano'], 200),
    ]);

    $result = gateway()->createCustomer(new CustomerInput(
        name: 'Fulano',
        document: '123.456.789-00',
        email: 'fulano@example.com',
    ));

    expect($result->externalId)->toBe('cus_123');

    Http::assertSent(fn ($request) => $request['cpfCnpj'] === '12345678900');
});

it('creates a charge and maps confirmed status', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/payments' => Http::response([
            'id' => 'pay_123',
            'status' => 'CONFIRMED',
            'invoiceUrl' => 'https://asaas.com/i/pay_123',
        ], 200),
    ]);

    $result = gateway()->createCharge(new ChargeInput(
        customerExternalId: 'cus_123',
        amountCents: 10000,
        dueDate: '2026-09-01',
        billingType: 'PIX',
        description: 'Assinatura mensal',
        externalReference: 'invoice-1',
    ));

    expect($result->externalId)->toBe('pay_123')
        ->and($result->status)->toBe('CONFIRMED')
        ->and($result->invoiceUrl)->toBe('https://asaas.com/i/pay_123');
});

it('maps overdue and refunded statuses', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/payments/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'OVERDUE'], 200),
    ]);

    expect(gateway()->fetchCharge('pay_1')->status)->toBe('OVERDUE');

    Http::fake([
        'api-sandbox.asaas.com/v3/payments/pay_2' => Http::response(['id' => 'pay_2', 'status' => 'PARTIALLY_REFUNDED'], 200),
    ]);

    expect(gateway()->fetchCharge('pay_2')->status)->toBe('REFUNDED');
});

it('creates a recurring checkout session', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/checkouts' => Http::response(['id' => 'checkout_1', 'link' => 'https://asaas.com/checkout/1'], 200),
    ]);

    $session = gateway()->createCheckoutSession([
        'items' => [['name' => 'Plano Pro', 'amount' => 19900, 'quantity' => 1]],
        'cycle' => 'monthly',
        'returnUrl' => 'https://app.notfy.com/success',
        'customerId' => 'cus_123',
    ]);

    expect($session['url'])->toBe('https://asaas.com/checkout/1');

    Http::assertSent(fn ($request) => $request['chargeTypes'] === ['RECURRENT']
        && $request['subscription']['cycle'] === 'MONTHLY');
});

it('throws when generating a billing portal session without an active subscription', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/subscriptions*' => Http::response(['data' => []], 200),
    ]);

    gateway()->createBillingPortalSession('cus_123', 'https://app.notfy.com/return');
})->throws(RuntimeException::class);

it('returns false when deleting a customer fails', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/customers/cus_404' => Http::response(['errors' => [['description' => 'not found']]], 404),
    ]);

    expect(gateway()->deleteCustomer('cus_404'))->toBeFalse();
});

it('creates, updates, fetches and cancels a native subscription', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/subscriptions' => Http::response(['id' => 'sub_1', 'status' => 'ACTIVE'], 200),
        'api-sandbox.asaas.com/v3/subscriptions/sub_1' => Http::response(['id' => 'sub_1', 'status' => 'ACTIVE', 'value' => 199.0], 200),
    ]);

    $created = gateway()->createSubscription(['customer' => 'cus_123', 'billingType' => 'PIX', 'value' => 199.0, 'cycle' => 'MONTHLY', 'nextDueDate' => '2026-09-01']);
    expect($created['id'])->toBe('sub_1');

    $updated = gateway()->updateSubscription('sub_1', ['value' => 199.0]);
    expect($updated['id'])->toBe('sub_1');

    $fetched = gateway()->fetchSubscription('sub_1');
    expect($fetched['status'])->toBe('ACTIVE');

    Http::fake([
        'api-sandbox.asaas.com/v3/subscriptions/sub_1' => Http::response(['deleted' => true], 200),
    ]);

    expect(gateway()->cancelSubscription('sub_1'))->toBeTrue();
});

it('returns null when fetching a subscription that does not exist', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/subscriptions/missing' => Http::response(['errors' => [['description' => 'not found']]], 404),
    ]);

    expect(gateway()->fetchSubscription('missing'))->toBeNull();
});
