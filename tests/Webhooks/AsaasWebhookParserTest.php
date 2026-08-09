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
