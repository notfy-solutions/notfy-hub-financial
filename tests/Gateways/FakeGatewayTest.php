<?php

declare(strict_types=1);

use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Domain\Enums\ChargeStatus;
use Notfy\HubFinancial\Domain\Enums\PaymentEventType;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
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
        paymentMethod: PaymentMethod::Pix,
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
        ->and($result->status)->toBe(ChargeStatus::Pending);
});

it('fetches a charge and returns its current status', function () {
    $gw = fakeGateway();
    $gw->createCharge(chargeInput('inv-2'));
    $gw->forceChargeStatus('fake_pay_inv-2', ChargeStatus::Confirmed);

    expect($gw->fetchCharge('fake_pay_inv-2')->status)->toBe(ChargeStatus::Confirmed);
});

it('fetches a non-existent charge and returns PENDING', function () {
    expect(fakeGateway()->fetchCharge('missing')->status)->toBe(ChargeStatus::Pending);
});

it('refunds a charge and sets its status to REFUNDED', function () {
    $gw = fakeGateway();
    $gw->createCharge(chargeInput('inv-3'));

    expect($gw->refundCharge('fake_pay_inv-3'))->toBeTrue()
        ->and($gw->fetchCharge('fake_pay_inv-3')->status)->toBe(ChargeStatus::Refunded);
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
        ->and($gw->fetchCharge('fake_pay_inv-5')->status)->toBe(ChargeStatus::Deleted);
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
    $gw->forceChargeStatus('fake_pay_inv-f', ChargeStatus::Overdue);

    expect($gw->fetchCharge('fake_pay_inv-f')->status)->toBe(ChargeStatus::Overdue);
});

it('forceChargeStatus() does nothing for a non-existent charge', function () {
    $gw = fakeGateway();
    $gw->forceChargeStatus('missing', ChargeStatus::Overdue);

    expect($gw->fetchCharge('missing')->status)->toBe(ChargeStatus::Pending);
});

// ─── StatementGateway ────────────────────────────────────────────────────────

use Notfy\HubFinancial\Domain\Enums\StatementEntryType;
use Notfy\HubFinancial\DTO\StatementEntry;
use Notfy\HubFinancial\DTO\StatementQuery;

it('returns the seeded statement entries inside the requested period and the balance', function () {
    $gw = fakeGateway();
    $gw->seedStatement([
        new StatementEntry('e1', StatementEntryType::Credit, 10000, 'USD', '2026-10-01', 'Payment received', 'inv-1'),
        new StatementEntry('e2', StatementEntryType::Debit, 2500, 'USD', '2026-10-15', 'Bank fee'),
        new StatementEntry('e3', StatementEntryType::Credit, 5000, 'USD', '2026-11-02', 'Next month'),
    ], 7500, 'USD');

    $entries = $gw->fetchStatement(new StatementQuery('2026-10-01', '2026-10-31'));
    $balance = $gw->fetchBalance();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->type)->toBe(StatementEntryType::Credit)
        ->and($entries[0]->reference)->toBe('inv-1')
        ->and($entries[1]->amountCents)->toBe(2500)
        ->and($balance->availableCents)->toBe(7500)
        ->and($balance->currency)->toBe('USD')
        ->and($balance->pendingCents)->toBeNull();
});

it('starts with an empty statement and a zero balance', function () {
    $gw = fakeGateway();

    expect($gw->fetchStatement(new StatementQuery('2026-01-01', '2026-12-31', 'acc-1')))->toBe([])
        ->and($gw->fetchBalance('acc-1')->availableCents)->toBe(0);
});

it('carries the international customer fields through the charge flow', function () {
    $gw = fakeGateway();

    $result = $gw->createCustomer(new CustomerInput(
        name: 'Acme Inc',
        document: '12-3456789',
        email: 'billing@acme.test',
        externalReference: 'cus-us-1',
        country: 'US',
        documentType: 'ein',
        addressLine: '1 Market St',
        city: 'San Francisco',
        state: 'CA',
    ));

    $stored = $gw->getCustomers()[$result->externalId]['input'];

    expect($stored->country)->toBe('US')
        ->and($stored->documentType)->toBe('ein')
        ->and($stored->state)->toBe('CA');
});
