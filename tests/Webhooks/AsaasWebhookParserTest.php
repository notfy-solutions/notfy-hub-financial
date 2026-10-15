<?php

declare(strict_types=1);

use Notfy\HubFinancial\Webhooks\AsaasWebhookParser;

it('validates the received token against the expected token', function () {
    $parser = new AsaasWebhookParser;

    expect($parser->isValid('secret-token', 'secret-token'))->toBeTrue()
        ->and($parser->isValid('wrong-token', 'secret-token'))->toBeFalse()
        ->and($parser->isValid(null, 'secret-token'))->toBeFalse()
        ->and($parser->isValid('secret-token', ''))->toBeFalse();
});

it('parses a payment webhook payload', function () {
    $parser = new AsaasWebhookParser;

    $event = $parser->parse([
        'id' => 'evt_1',
        'event' => 'PAYMENT_CONFIRMED',
        'payment' => [
            'id' => 'pay_123',
            'externalReference' => 'invoice-1',
            'status' => 'CONFIRMED',
        ],
    ]);

    expect($event->type)->toBe('PAYMENT_CONFIRMED')
        ->and($event->paymentId)->toBe('pay_123')
        ->and($event->externalReference)->toBe('invoice-1')
        ->and($event->status)->toBe('CONFIRMED')
        ->and($event->isPaymentEvent())->toBeTrue();
});

it('handles unknown event payloads gracefully', function () {
    $parser = new AsaasWebhookParser;

    $event = $parser->parse([]);

    expect($event->type)->toBe('UNKNOWN')
        ->and($event->paymentId)->toBeNull()
        ->and($event->isPaymentEvent())->toBeFalse();
});

// ─── WebhookHandler contract ─────────────────────────────────────────────────

use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Domain\Enums\PaymentEventType;
use Notfy\HubFinancial\Support\GatewayAccount;

it('verify() passes when the header token matches the account webhook secret', function () {
    $parser = new AsaasWebhookParser;
    $account = new GatewayAccount('asaas', new ApiKey('key'), webhookSecret: 'secret-token');

    expect($parser->verify('{}', ['asaas-access-token' => 'secret-token'], $account))->toBeTrue()
        ->and($parser->verify('{}', ['Asaas-Access-Token' => 'secret-token'], $account))->toBeTrue()
        ->and($parser->verify('{}', ['asaas-access-token' => 'wrong'], $account))->toBeFalse()
        ->and($parser->verify('{}', [], $account))->toBeFalse();
});

it('normalize() returns a PaymentEvent with all fields mapped', function () {
    $parser = new AsaasWebhookParser;

    $payload = [
        'event' => 'PAYMENT_CONFIRMED',
        'payment' => [
            'id' => 'pay_123',
            'externalReference' => 'invoice-1',
            'status' => 'CONFIRMED',
            'value' => 100.00,
            'fee' => 1.50,
            'confirmedDate' => '2026-01-15',
        ],
    ];

    $event = $parser->normalize($payload);

    expect($event->type)->toBe(PaymentEventType::Confirmed)
        ->and($event->chargeId)->toBe('pay_123')
        ->and($event->externalReference)->toBe('invoice-1')
        ->and($event->amountPaidCents)->toBe(10000)
        ->and($event->feeCents)->toBe(150)
        ->and($event->paidAt)->toBe('2026-01-15')
        ->and($event->provider)->toBe('asaas')
        ->and($event->eventId)->toBe('pay_123:PAYMENT_CONFIRMED')
        ->and($event->raw)->toBe($payload);
});

it('normalize() uses paymentDate as fallback for paidAt', function () {
    $parser = new AsaasWebhookParser;

    $event = $parser->normalize([
        'event' => 'PAYMENT_RECEIVED',
        'payment' => ['id' => 'pay_1', 'paymentDate' => '2026-02-01'],
    ]);

    expect($event->paidAt)->toBe('2026-02-01');
});

it('normalize() returns null amountPaidCents and feeCents when value and fee are absent', function () {
    $parser = new AsaasWebhookParser;

    $event = $parser->normalize(['event' => 'PAYMENT_CREATED', 'payment' => ['id' => 'pay_1']]);

    expect($event->amountPaidCents)->toBeNull()
        ->and($event->feeCents)->toBeNull();
});

it('normalize() builds eventId from event type alone when chargeId is empty', function () {
    $parser = new AsaasWebhookParser;

    $event = $parser->normalize(['event' => 'PAYMENT_DELETED', 'payment' => []]);

    expect($event->eventId)->toBe('PAYMENT_DELETED');
});

it('normalize() maps every Asaas event type to the correct PaymentEventType', function (string $asaasEvent, PaymentEventType $expected) {
    $parser = new AsaasWebhookParser;

    $event = $parser->normalize(['event' => $asaasEvent, 'payment' => ['id' => 'p']]);

    expect($event->type)->toBe($expected);
})->with([
    ['PAYMENT_CREATED', PaymentEventType::Created],
    ['PAYMENT_CONFIRMED', PaymentEventType::Confirmed],
    ['PAYMENT_RECEIVED', PaymentEventType::Received],
    ['PAYMENT_OVERDUE', PaymentEventType::Overdue],
    ['PAYMENT_REFUNDED', PaymentEventType::Refunded],
    ['PAYMENT_REFUND_IN_PROGRESS', PaymentEventType::Refunded],
    ['PAYMENT_CHARGEBACK_DISPUTE', PaymentEventType::Chargeback],
    ['PAYMENT_CHARGEBACK_REQUESTED', PaymentEventType::Chargeback],
    ['PAYMENT_DELETED', PaymentEventType::Cancelled],
    ['PAYMENT_UPDATED', PaymentEventType::Updated],
    ['PAYMENT_UNKNOWN_FUTURE_TYPE', PaymentEventType::Unknown],
]);
