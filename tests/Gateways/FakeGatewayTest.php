<?php

declare(strict_types=1);

use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Domain\Enums\PaymentEventType;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\Gateways\Fake\FakeGateway;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Webhooks\PaymentEvent;

function fakeGateway(): FakeGateway
{
    return new FakeGateway;
}

function chargeInput(string $ref = 'inv-1'): ChargeInput
{
    return new ChargeInput(
        customerExternalId: 'cus_1',
        amountCents: 5000,
        dueDate: '2026-09-01',
        billingType: 'PIX',
        description: 'Cobrança',
        externalReference: $ref,
    );
}

// ─── Customer ────────────────────────────────────────────────────────────────

it('creates a customer and returns a fake id based on externalReference', function () {
    $result = fakeGateway()->createCustomer(new CustomerInput(
        name: 'Fulano',
        document: '123',
        externalReference: 'client-1',
    ));

    expect($result->externalId)->toBe('fake_cus_client-1');
});

it('updates a customer', function () {
    $gw = fakeGateway();
    $gw->createCustomer(new CustomerInput(name: 'A', document: '1', externalReference: 'c1'));
    $result = $gw->updateCustomer('fake_cus_c1', new CustomerInput(name: 'B', document: '1'));

    expect($result->externalId)->toBe('fake_cus_c1');
});

it('deletes a customer and removes it from the store', function () {
    $gw = fakeGateway();
    $gw->createCustomer(new CustomerInput(name: 'A', document: '1', externalReference: 'c1'));

    expect($gw->deleteCustomer('fake_cus_c1'))->toBeTrue()
        ->and($gw->getCustomers())->toBeEmpty();
});

// ─── Charge ──────────────────────────────────────────────────────────────────

it('creates a charge with PENDING status', function () {
    $result = fakeGateway()->createCharge(chargeInput('inv-1'));

    expect($result->externalId)->toBe('fake_pay_inv-1')
        ->and($result->status)->toBe('PENDING');
});

it('fetches a charge and returns its current status', function () {
    $gw = fakeGateway();
    $gw->createCharge(chargeInput('inv-2'));
    $gw->forceChargeStatus('fake_pay_inv-2', 'CONFIRMED');

    expect($gw->fetchCharge('fake_pay_inv-2')->status)->toBe('CONFIRMED');
});

it('fetches a non-existent charge and returns PENDING', function () {
    expect(fakeGateway()->fetchCharge('missing')->status)->toBe('PENDING');
});

it('refunds a charge and sets its status to REFUNDED', function () {
    $gw = fakeGateway();
    $gw->createCharge(chargeInput('inv-3'));

    expect($gw->refundCharge('fake_pay_inv-3'))->toBeTrue()
        ->and($gw->fetchCharge('fake_pay_inv-3')->status)->toBe('REFUNDED');
});

it('refunds a charge partially', function () {
    $gw = fakeGateway();
    $gw->createCharge(chargeInput('inv-4'));

    expect($gw->refundCharge('fake_pay_inv-4', 2500))->toBeTrue();
});

it('cancels a charge and sets its status to DELETED', function () {
    $gw = fakeGateway();
    $gw->createCharge(chargeInput('inv-5'));

    expect($gw->cancelCharge('fake_pay_inv-5'))->toBeTrue()
        ->and($gw->fetchCharge('fake_pay_inv-5')->status)->toBe('DELETED');
});

it('getCharges() returns all created charges', function () {
    $gw = fakeGateway();
    $gw->createCharge(chargeInput('inv-a'));
    $gw->createCharge(chargeInput('inv-b'));

    expect($gw->getCharges())->toHaveCount(2);
});

// ─── Checkout ────────────────────────────────────────────────────────────────

it('createCheckoutSession() returns a fake url', function () {
    $session = fakeGateway()->createCheckoutSession(['items' => []]);

    expect($session['url'])->toBe('https://fake.gateway/checkout/1')
        ->and($session['id'])->toBe('fake_checkout_1');
});

it('createBillingPortalSession() returns a fake url', function () {
    $session = fakeGateway()->createBillingPortalSession('cus_1', 'https://return');

    expect($session['url'])->toBe('https://fake.gateway/portal/1');
});

// ─── Subscription ────────────────────────────────────────────────────────────

it('creates, updates, fetches and cancels a subscription', function () {
    $gw = fakeGateway();

    $created = $gw->createSubscription(['customer' => 'cus_1', 'value' => 99.0, 'cycle' => 'MONTHLY']);
    expect($created['status'])->toBe('ACTIVE')
        ->and($created['cycle'])->toBe('MONTHLY');

    $id = $created['id'];

    $updated = $gw->updateSubscription($id, ['value' => 149.0]);
    expect($updated['value'])->toBe(149.0);

    expect($gw->fetchSubscription($id)['value'])->toBe(149.0);

    expect($gw->cancelSubscription($id))->toBeTrue()
        ->and($gw->fetchSubscription($id)['status'])->toBe('INACTIVE');
});

it('fetchSubscription() returns null for an unknown id', function () {
    expect(fakeGateway()->fetchSubscription('missing'))->toBeNull();
});

it('getSubscriptions() returns all created subscriptions', function () {
    $gw = fakeGateway();
    $gw->createSubscription(['customer' => 'cus_1']);
    $gw->createSubscription(['customer' => 'cus_2']);

    expect($gw->getSubscriptions())->toHaveCount(2);
});

it('getCustomers() returns all created customers', function () {
    $gw = fakeGateway();
    $gw->createCustomer(new CustomerInput(name: 'A', document: '1', externalReference: 'c1'));
    $gw->createCustomer(new CustomerInput(name: 'B', document: '2', externalReference: 'c2'));

    expect($gw->getCustomers())->toHaveCount(2);
});

// ─── WebhookHandler ──────────────────────────────────────────────────────────

it('verify() always returns true', function () {
    $gw = fakeGateway();
    $account = new GatewayAccount('fake', new ApiKey('key'));

    expect($gw->verify('{}', [], $account))->toBeTrue();
});

it('normalize() returns a PaymentEvent with type Unknown', function () {
    $gw = fakeGateway();
    $account = new GatewayAccount('fake', new ApiKey('key'));

    $event = $gw->normalize(['id' => 'evt_1']);

    expect($event)->toBeInstanceOf(PaymentEvent::class)
        ->and($event->type)->toBe(PaymentEventType::Unknown)
        ->and($event->chargeId)->toBe('evt_1')
        ->and($event->provider)->toBe('fake');
});

it('normalize() handles payload without id', function () {
    $event = fakeGateway()->normalize([]);

    expect($event->chargeId)->toBe('');
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

it('forceChargeStatus() overrides the status of an existing charge', function () {
    $gw = fakeGateway();
    $gw->createCharge(chargeInput('inv-f'));
    $gw->forceChargeStatus('fake_pay_inv-f', 'OVERDUE');

    expect($gw->fetchCharge('fake_pay_inv-f')->status)->toBe('OVERDUE');
});

it('forceChargeStatus() does nothing for a non-existent charge', function () {
    $gw = fakeGateway();
    $gw->forceChargeStatus('missing', 'OVERDUE');

    expect($gw->fetchCharge('missing')->status)->toBe('PENDING');
});
