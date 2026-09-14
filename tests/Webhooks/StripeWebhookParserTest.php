<?php

declare(strict_types=1);

use Notfy\HubFinancial\Webhooks\StripeWebhookParser;

function stripeSignatureHeader(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return "t={$timestamp},v1={$signature}";
}

it('validates a correctly signed payload', function () {
    $parser = new StripeWebhookParser;
    $payload = '{"id":"evt_1"}';
    $header = stripeSignatureHeader($payload, 'whsec_test');

    expect($parser->isValid($payload, $header, 'whsec_test'))->toBeTrue();
});

it('rejects a payload signed with the wrong secret', function () {
    $parser = new StripeWebhookParser;
    $payload = '{"id":"evt_1"}';
    $header = stripeSignatureHeader($payload, 'whsec_wrong');

    expect($parser->isValid($payload, $header, 'whsec_test'))->toBeFalse();
});

it('rejects a tampered payload', function () {
    $parser = new StripeWebhookParser;
    $header = stripeSignatureHeader('{"id":"evt_1"}', 'whsec_test');

    expect($parser->isValid('{"id":"evt_2"}', $header, 'whsec_test'))->toBeFalse();
});

it('rejects a signature older than the tolerance window', function () {
    $parser = new StripeWebhookParser;
    $payload = '{"id":"evt_1"}';
    $header = stripeSignatureHeader($payload, 'whsec_test', time() - 600);

    expect($parser->isValid($payload, $header, 'whsec_test'))->toBeFalse();
});

it('accepts a custom tolerance window', function () {
    $parser = new StripeWebhookParser;
    $payload = '{"id":"evt_1"}';
    $header = stripeSignatureHeader($payload, 'whsec_test', time() - 600);

    expect($parser->isValid($payload, $header, 'whsec_test', 900))->toBeTrue();
});

it('rejects when the signature header is missing or empty', function () {
    $parser = new StripeWebhookParser;

    expect($parser->isValid('{}', null, 'whsec_test'))->toBeFalse()
        ->and($parser->isValid('{}', '', 'whsec_test'))->toBeFalse();
});

it('rejects when the webhook secret is empty', function () {
    $parser = new StripeWebhookParser;
    $header = stripeSignatureHeader('{}', 'whsec_test');

    expect($parser->isValid('{}', $header, ''))->toBeFalse();
});

it('rejects a malformed signature header without timestamp or v1 signature', function () {
    $parser = new StripeWebhookParser;

    expect($parser->isValid('{}', 'garbage-header', 'whsec_test'))->toBeFalse()
        ->and($parser->isValid('{}', 'v1=abc', 'whsec_test'))->toBeFalse()
        ->and($parser->isValid('{}', 't='.time(), 'whsec_test'))->toBeFalse();
});

it('accepts when at least one of multiple v1 signatures matches (secret rotation)', function () {
    $parser = new StripeWebhookParser;
    $payload = '{"id":"evt_1"}';
    $timestamp = time();
    $validSignature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');
    $header = "t={$timestamp},v1=deadbeef,v1={$validSignature}";

    expect($parser->isValid($payload, $header, 'whsec_test'))->toBeTrue();
});

it('parses a payment_intent event and extracts the external reference from metadata', function () {
    $parser = new StripeWebhookParser;

    $event = $parser->parse([
        'type' => 'payment_intent.succeeded',
        'data' => [
            'object' => [
                'id' => 'pi_123',
                'status' => 'succeeded',
                'metadata' => ['external_reference' => 'invoice-1'],
            ],
        ],
    ]);

    expect($event->type)->toBe('payment_intent.succeeded')
        ->and($event->objectId)->toBe('pi_123')
        ->and($event->externalReference)->toBe('invoice-1')
        ->and($event->status)->toBe('succeeded')
        ->and($event->isPaymentEvent())->toBeTrue()
        ->and($event->isRefundEvent())->toBeFalse();
});

it('parses a refund event', function () {
    $parser = new StripeWebhookParser;

    $event = $parser->parse([
        'type' => 'charge.refunded',
        'data' => ['object' => ['id' => 'ch_123', 'status' => 'succeeded']],
    ]);

    expect($event->isRefundEvent())->toBeTrue()
        ->and($event->isPaymentEvent())->toBeFalse();
});

it('handles unknown event payloads gracefully', function () {
    $parser = new StripeWebhookParser;

    $event = $parser->parse([]);

    expect($event->type)->toBe('unknown')
        ->and($event->objectId)->toBeNull()
        ->and($event->externalReference)->toBeNull()
        ->and($event->status)->toBeNull()
        ->and($event->isPaymentEvent())->toBeFalse();
});
