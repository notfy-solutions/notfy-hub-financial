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

it('returns true when a customer is deleted', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/customers/cus_123' => Http::response(['deleted' => true], 200),
    ]);

    expect(gateway()->deleteCustomer('cus_123'))->toBeTrue();
});

it('maps deleted and unknown statuses', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/payments/pay_deleted' => Http::response(['id' => 'pay_deleted', 'status' => 'DELETED'], 200),
    ]);

    expect(gateway()->fetchCharge('pay_deleted')->status)->toBe('DELETED');

    Http::fake([
        'api-sandbox.asaas.com/v3/payments/pay_received' => Http::response(['id' => 'pay_received', 'status' => 'RECEIVED_IN_CASH'], 200),
    ]);

    expect(gateway()->fetchCharge('pay_received')->status)->toBe('RECEIVED');

    Http::fake([
        'api-sandbox.asaas.com/v3/payments/pay_unknown' => Http::response(['id' => 'pay_unknown', 'status' => 'AWAITING_RISK_ANALYSIS'], 200),
    ]);

    expect(gateway()->fetchCharge('pay_unknown')->status)->toBe('PENDING');
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

it('updates a customer', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/customers/cus_123' => Http::response(['id' => 'cus_123', 'name' => 'Fulano Editado'], 200),
    ]);

    $result = gateway()->updateCustomer('cus_123', new CustomerInput(
        name: 'Fulano Editado',
        document: '123.456.789-00',
        email: 'fulano@example.com',
        phone: '11999999999',
        externalReference: 'client-1',
        postalCode: '01000-000',
        addressNumber: '100',
    ));

    expect($result->externalId)->toBe('cus_123');

    Http::assertSent(fn ($request) => $request['cpfCnpj'] === '12345678900'
        && $request['mobilePhone'] === '11999999999'
        && $request['externalReference'] === 'client-1'
        && $request['postalCode'] === '01000-000'
        && $request['addressNumber'] === '100');
});

it('refunds a charge fully and partially', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/payments/pay_1/refund' => Http::response(['id' => 'pay_1', 'status' => 'REFUNDED'], 200),
    ]);

    expect(gateway()->refundCharge('pay_1'))->toBeTrue();
    expect(gateway()->refundCharge('pay_1', 5000))->toBeTrue();

    Http::assertSent(fn ($request) => $request->data() === []);
    Http::assertSent(fn ($request) => ($request['value'] ?? null) === 50.0);
});

it('returns false when a refund fails', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/payments/pay_err/refund' => Http::response(['errors' => [['description' => 'already refunded']]], 400),
    ]);

    expect(gateway()->refundCharge('pay_err'))->toBeFalse();
});

it('cancels a charge', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/payments/pay_1' => Http::response(['deleted' => true], 200),
    ]);

    expect(gateway()->cancelCharge('pay_1'))->toBeTrue();
});

it('returns false when canceling a charge fails', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/payments/pay_err' => Http::response(['errors' => [['description' => 'not found']]], 404),
    ]);

    expect(gateway()->cancelCharge('pay_err'))->toBeFalse();
});

it('returns false when canceling a subscription fails', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/subscriptions/sub_err' => Http::response(['errors' => [['description' => 'not found']]], 404),
    ]);

    expect(gateway()->cancelSubscription('sub_err'))->toBeFalse();
});

it('generates a billing portal session from the active subscription', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/subscriptions*' => Http::response([
            'data' => [[
                'id' => 'sub_1',
                'description' => 'Plano Pro',
                'value' => 199.0,
                'cycle' => 'MONTHLY',
            ]],
        ], 200),
        'api-sandbox.asaas.com/v3/checkouts' => Http::response(['id' => 'checkout_1', 'link' => 'https://asaas.com/checkout/1'], 200),
    ]);

    $session = gateway()->createBillingPortalSession('cus_123', 'https://app.notfy.com/return');

    expect($session['url'])->toBe('https://asaas.com/checkout/1');

    Http::assertSent(fn ($request) => ($request['items'][0]['name'] ?? null) === 'Plano Pro'
        && $request['items'][0]['value'] === 199.0
        && $request['subscription']['cycle'] === 'MONTHLY');
});

it('generates a billing portal session using default values when the subscription lacks them', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/subscriptions*' => Http::response([
            'data' => [['id' => 'sub_2']],
        ], 200),
        'api-sandbox.asaas.com/v3/checkouts' => Http::response(['id' => 'checkout_2', 'link' => 'https://asaas.com/checkout/2'], 200),
    ]);

    gateway()->createBillingPortalSession('cus_123', 'https://app.notfy.com/return');

    Http::assertSent(fn ($request) => ($request['items'][0]['name'] ?? null) === 'Assinatura'
        && $request['subscription']['cycle'] === 'MONTHLY');
});

it('creates a checkout session using inline customer data instead of a customer id', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/checkouts' => Http::response(['id' => 'checkout_3', 'link' => 'https://asaas.com/checkout/3'], 200),
    ]);

    gateway()->createCheckoutSession([
        'items' => [['name' => 'Plano Pro', 'amount' => 19900, 'quantity' => 1]],
        'returnUrl' => 'https://app.notfy.com/success',
        'customerData' => ['name' => 'Fulano', 'email' => 'fulano@example.com'],
        'externalReference' => 'checkout-ref-1',
    ]);

    Http::assertSent(fn ($request) => $request['customerData'] === ['name' => 'Fulano', 'email' => 'fulano@example.com']
        && $request['externalReference'] === 'checkout-ref-1'
        && ! array_key_exists('customer', $request->data()));
});
