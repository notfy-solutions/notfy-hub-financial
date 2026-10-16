<?php

declare(strict_types=1);

use Notfy\HubFinancial\Domain\Enums\ChargeStatus;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\Exceptions\StripeApiException;
use Notfy\HubFinancial\Gateways\Stripe\StripeBillingGateway;
use Notfy\HubFinancial\Tests\Support\FakeStripeHttpClient;
use Stripe\ApiRequestor;
use Stripe\StripeClient;

function stripeGateway(): StripeBillingGateway
{
    return new StripeBillingGateway(new StripeClient('sk_test_123'), 'usd');
}

function chargeInputWithType(PaymentMethod $paymentMethod, string $externalReference): ChargeInput
{
    return new ChargeInput(
        customerExternalId: 'cus_123',
        amountCents: 1000,
        dueDate: '2026-09-01',
        paymentMethod: $paymentMethod,
        description: 'Cobrança',
        externalReference: $externalReference,
    );
}

function stripeHttpFake(?FakeStripeHttpClient $set = null): FakeStripeHttpClient
{
    static $client = null;

    if ($set !== null) {
        $client = $set;
    }

    return $client ??= new FakeStripeHttpClient;
}

beforeEach(function () {
    ApiRequestor::setHttpClient(stripeHttpFake(new FakeStripeHttpClient));
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

it('creates a customer with an idempotency key derived from the external reference', function () {
    stripeHttpFake()->queueResponse('post', '/v1/customers', ['id' => 'cus_123', 'object' => 'customer', 'name' => 'Fulano']);

    $result = stripeGateway()->createCustomer(new CustomerInput(
        name: 'Fulano',
        document: '123.456.789-00',
        email: 'fulano@example.com',
        externalReference: 'client-1',
    ));

    expect($result->externalId)->toBe('cus_123');

    $request = stripeHttpFake()->requests[0];
    expect($request['params']['name'])->toBe('Fulano')
        ->and($request['params']['metadata']['document'])->toBe('123.456.789-00')
        ->and($request['params']['metadata']['external_reference'])->toBe('client-1')
        ->and(stripeHttpFake()->hasHeader(0, 'Idempotency-Key', 'customer:client-1'))->toBeTrue();
});

it('creates a customer without an idempotency key when there is no external reference', function () {
    stripeHttpFake()->queueResponse('post', '/v1/customers', ['id' => 'cus_124', 'object' => 'customer']);

    stripeGateway()->createCustomer(new CustomerInput(name: 'Fulano', document: '123.456.789-00'));

    expect(stripeHttpFake()->hasHeader(0, 'Idempotency-Key'))->toBeFalse();
});

it('translates a Stripe API error into a StripeApiException when creating a customer', function () {
    stripeHttpFake()->queueResponse('post', '/v1/customers', [
        'error' => ['type' => 'invalid_request_error', 'message' => 'Invalid email address'],
    ], 400);

    try {
        stripeGateway()->createCustomer(new CustomerInput(name: 'Fulano', document: '123.456.789-00'));
        test()->fail('Expected StripeApiException to be thrown.');
    } catch (StripeApiException $exception) {
        expect($exception->getMessage())->toBe('Invalid email address')
            ->and($exception->getStatusCode())->toBe(400)
            ->and($exception->getPayload())->toHaveKey('error');
    }
});

it('updates a customer', function () {
    stripeHttpFake()->queueResponse('post', '/v1/customers/cus_123', ['id' => 'cus_123', 'object' => 'customer', 'name' => 'Fulano Editado']);

    $result = stripeGateway()->updateCustomer('cus_123', new CustomerInput(
        name: 'Fulano Editado',
        document: '123.456.789-00',
        phone: '+5511999999999',
    ));

    expect($result->externalId)->toBe('cus_123');

    $request = stripeHttpFake()->requests[0];
    expect($request['params']['name'])->toBe('Fulano Editado')
        ->and($request['params']['phone'])->toBe('+5511999999999');
});

it('translates a Stripe API error into a StripeApiException when updating a customer', function () {
    stripeHttpFake()->queueResponse('post', '/v1/customers/cus_err', [
        'error' => ['type' => 'invalid_request_error', 'message' => 'No such customer'],
    ], 404);

    stripeGateway()->updateCustomer('cus_err', new CustomerInput(name: 'Fulano', document: '123.456.789-00'));
})->throws(StripeApiException::class, 'No such customer');

it('deletes a customer', function () {
    stripeHttpFake()->queueResponse('delete', '/v1/customers/cus_123', ['id' => 'cus_123', 'object' => 'customer', 'deleted' => true]);

    expect(stripeGateway()->deleteCustomer('cus_123'))->toBeTrue();
});

it('returns false when deleting a customer fails', function () {
    stripeHttpFake()->queueResponse('delete', '/v1/customers/cus_404', [
        'error' => ['type' => 'invalid_request_error', 'message' => 'No such customer'],
    ], 404);

    expect(stripeGateway()->deleteCustomer('cus_404'))->toBeFalse();
});

it('creates and auto-confirms a charge when a payment method token is given', function () {
    stripeHttpFake()->queueResponse('post', '/v1/payment_intents', [
        'id' => 'pi_123',
        'object' => 'payment_intent',
        'status' => 'succeeded',
    ]);

    $result = stripeGateway()->createCharge(new ChargeInput(
        customerExternalId: 'cus_123',
        amountCents: 10000,
        dueDate: '2026-09-01',
        paymentMethod: PaymentMethod::Card,
        description: 'Assinatura mensal',
        externalReference: 'invoice-1',
        cardToken: 'pm_card_visa',
    ));

    expect($result->externalId)->toBe('pi_123')
        ->and($result->status)->toBe(ChargeStatus::Received);

    $request = stripeHttpFake()->requests[0];
    expect($request['params']['amount'])->toBe(10000)
        ->and($request['params']['currency'])->toBe('usd')
        ->and($request['params']['payment_method'])->toBe('pm_card_visa')
        // stripe-php's ApiRequestor::_encodeObjects() stringifies booleans
        // ('true'/'false') before form-encoding the request body.
        ->and($request['params']['confirm'])->toBe('true')
        ->and($request['params']['payment_method_types'])->toBe(['card'])
        ->and(stripeHttpFake()->hasHeader(0, 'Idempotency-Key', 'charge:invoice-1'))->toBeTrue();
});

it('translates a Stripe API error into a StripeApiException when creating a charge', function () {
    stripeHttpFake()->queueResponse('post', '/v1/payment_intents', [
        'error' => ['type' => 'card_error', 'message' => 'Your card was declined.'],
    ], 402);

    stripeGateway()->createCharge(chargeInputWithType(PaymentMethod::Card, 'invoice-declined'));
})->throws(StripeApiException::class, 'Your card was declined.');

it('translates a Stripe API error into a StripeApiException when fetching a charge', function () {
    stripeHttpFake()->queueResponse('get', '/v1/payment_intents/pi_missing', [
        'error' => ['type' => 'invalid_request_error', 'message' => 'No such payment_intent'],
    ], 404);

    stripeGateway()->fetchCharge('pi_missing');
})->throws(StripeApiException::class, 'No such payment_intent');

it('creates a charge without confirming it when there is no payment method token', function () {
    stripeHttpFake()->queueResponse('post', '/v1/payment_intents', [
        'id' => 'pi_124',
        'object' => 'payment_intent',
        'status' => 'requires_payment_method',
    ]);

    $result = stripeGateway()->createCharge(new ChargeInput(
        customerExternalId: 'cus_123',
        amountCents: 5000,
        dueDate: '2026-09-01',
        paymentMethod: PaymentMethod::Undefined,
        description: 'Cobrança avulsa',
        externalReference: 'invoice-2',
    ));

    expect($result->status)->toBe(ChargeStatus::Pending);

    $request = stripeHttpFake()->requests[0];
    expect(array_key_exists('confirm', $request['params']))->toBeFalse()
        ->and(array_key_exists('payment_method', $request['params']))->toBeFalse();
});

it('maps billing types to Stripe payment method types', function () {
    stripeHttpFake()->queueResponse('post', '/v1/payment_intents', ['id' => 'pi_boleto', 'object' => 'payment_intent', 'status' => 'requires_action']);
    stripeGateway()->createCharge(chargeInputWithType(PaymentMethod::Boleto, 'invoice-boleto'));
    expect(stripeHttpFake()->requests[0]['params']['payment_method_types'])->toBe(['boleto']);

    stripeHttpFake()->queueResponse('post', '/v1/payment_intents', ['id' => 'pi_pix', 'object' => 'payment_intent', 'status' => 'requires_action']);
    stripeGateway()->createCharge(chargeInputWithType(PaymentMethod::Pix, 'invoice-pix'));
    expect(stripeHttpFake()->requests[1]['params']['payment_method_types'])->toBe(['pix']);
});

it('extracts boleto and pix details from next_action', function () {
    stripeHttpFake()->queueResponse('get', '/v1/payment_intents/pi_boleto', [
        'id' => 'pi_boleto',
        'object' => 'payment_intent',
        'status' => 'requires_action',
        'next_action' => [
            'type' => 'boleto_display_details',
            'boleto_display_details' => [
                'hosted_voucher_url' => 'https://stripe.com/boleto/voucher',
                'pdf' => 'https://stripe.com/boleto/pdf',
            ],
        ],
    ]);

    $result = stripeGateway()->fetchCharge('pi_boleto');

    expect($result->invoiceUrl)->toBe('https://stripe.com/boleto/voucher')
        ->and($result->bankSlipUrl)->toBe('https://stripe.com/boleto/pdf');

    stripeHttpFake()->queueResponse('get', '/v1/payment_intents/pi_pix', [
        'id' => 'pi_pix',
        'object' => 'payment_intent',
        'status' => 'requires_action',
        'next_action' => [
            'type' => 'pix_display_qr_code',
            'pix_display_qr_code' => [
                'hosted_instructions_url' => 'https://stripe.com/pix/instructions',
                'data' => '00020126...copy-paste',
            ],
        ],
    ]);

    $result = stripeGateway()->fetchCharge('pi_pix');

    expect($result->invoiceUrl)->toBe('https://stripe.com/pix/instructions')
        ->and($result->pixCopyPaste)->toBe('00020126...copy-paste');
});

it('maps every payment intent status to the canonical charge status', function (string $stripeStatus, ChargeStatus $expected) {
    stripeHttpFake()->queueResponse('get', "/v1/payment_intents/pi_{$stripeStatus}", [
        'id' => "pi_{$stripeStatus}",
        'object' => 'payment_intent',
        'status' => $stripeStatus,
    ]);

    expect(stripeGateway()->fetchCharge("pi_{$stripeStatus}")->status)->toBe($expected);
})->with([
    ['succeeded', ChargeStatus::Received],
    ['requires_capture', ChargeStatus::Confirmed],
    ['canceled', ChargeStatus::Deleted],
    ['processing', ChargeStatus::Pending],
    ['requires_action', ChargeStatus::Pending],
    ['requires_confirmation', ChargeStatus::Pending],
    ['requires_payment_method', ChargeStatus::Pending],
    ['some_future_status', ChargeStatus::Pending],
]);

it('refunds a charge fully and partially', function () {
    stripeHttpFake()->queueResponse('post', '/v1/refunds', ['id' => 're_1', 'object' => 'refund', 'status' => 'succeeded']);
    expect(stripeGateway()->refundCharge('pi_123'))->toBeTrue();

    stripeHttpFake()->queueResponse('post', '/v1/refunds', ['id' => 're_2', 'object' => 'refund', 'status' => 'succeeded']);
    expect(stripeGateway()->refundCharge('pi_123', 5000))->toBeTrue();

    expect(array_key_exists('amount', stripeHttpFake()->requests[0]['params']))->toBeFalse()
        ->and(stripeHttpFake()->requests[1]['params']['amount'])->toBe(5000);
});

it('returns false when a refund fails', function () {
    stripeHttpFake()->queueResponse('post', '/v1/refunds', [
        'error' => ['type' => 'invalid_request_error', 'message' => 'Charge already refunded'],
    ], 400);

    expect(stripeGateway()->refundCharge('pi_err'))->toBeFalse();
});

it('cancels a charge', function () {
    stripeHttpFake()->queueResponse('post', '/v1/payment_intents/pi_123/cancel', ['id' => 'pi_123', 'object' => 'payment_intent', 'status' => 'canceled']);

    expect(stripeGateway()->cancelCharge('pi_123'))->toBeTrue();
});

it('returns false when canceling a charge fails', function () {
    stripeHttpFake()->queueResponse('post', '/v1/payment_intents/pi_err/cancel', [
        'error' => ['type' => 'invalid_request_error', 'message' => 'Already succeeded'],
    ], 400);

    expect(stripeGateway()->cancelCharge('pi_err'))->toBeFalse();
});

it('creates a one-off checkout session with a customer id', function () {
    stripeHttpFake()->queueResponse('post', '/v1/checkout/sessions', [
        'id' => 'cs_123',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/cs_123',
    ]);

    $session = stripeGateway()->createCheckoutSession([
        'items' => [['name' => 'Plano Pro', 'amount' => 19900, 'quantity' => 1]],
        'returnUrl' => 'https://app.notfy.com/return',
        'customerId' => 'cus_123',
        'externalReference' => 'checkout-1',
    ]);

    expect($session['id'])->toBe('cs_123')
        ->and($session['url'])->toBe('https://checkout.stripe.com/cs_123');

    $params = stripeHttpFake()->requests[0]['params'];
    expect($params['mode'])->toBe('payment')
        ->and($params['customer'])->toBe('cus_123')
        ->and(array_key_exists('customer_email', $params))->toBeFalse()
        ->and($params['line_items'][0]['price_data']['unit_amount'])->toBe(19900)
        ->and($params['line_items'][0]['price_data']['product_data']['name'])->toBe('Plano Pro')
        ->and(array_key_exists('recurring', $params['line_items'][0]['price_data']))->toBeFalse()
        ->and($params['metadata']['external_reference'])->toBe('checkout-1');
});

it('creates a recurring checkout session with a customer email', function () {
    stripeHttpFake()->queueResponse('post', '/v1/checkout/sessions', [
        'id' => 'cs_124',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/cs_124',
    ]);

    stripeGateway()->createCheckoutSession([
        'items' => [['name' => 'Plano Pro Anual', 'amount' => 199900]],
        'returnUrl' => 'https://app.notfy.com/return',
        'customerEmail' => 'fulano@example.com',
        'cycle' => 'yearly',
    ]);

    $params = stripeHttpFake()->requests[0]['params'];
    expect($params['mode'])->toBe('subscription')
        ->and($params['customer_email'])->toBe('fulano@example.com')
        ->and(array_key_exists('customer', $params))->toBeFalse()
        ->and($params['line_items'][0]['price_data']['recurring']['interval'])->toBe('year')
        ->and($params['line_items'][0]['quantity'])->toBe(1);
});

it('maps weekly and monthly billing cycles to the Stripe recurring interval', function () {
    stripeHttpFake()->queueResponse('post', '/v1/checkout/sessions', ['id' => 'cs_w', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/cs_w']);
    stripeGateway()->createCheckoutSession(['items' => [['name' => 'X', 'amount' => 100]], 'returnUrl' => 'https://x', 'cycle' => 'weekly']);
    expect(stripeHttpFake()->requests[0]['params']['line_items'][0]['price_data']['recurring']['interval'])->toBe('week');

    stripeHttpFake()->queueResponse('post', '/v1/checkout/sessions', ['id' => 'cs_m', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/cs_m']);
    stripeGateway()->createCheckoutSession(['items' => [['name' => 'X', 'amount' => 100]], 'returnUrl' => 'https://x', 'cycle' => 'monthly']);
    expect(stripeHttpFake()->requests[1]['params']['line_items'][0]['price_data']['recurring']['interval'])->toBe('month');
});

it('translates a Stripe API error into a StripeApiException when creating a checkout session', function () {
    stripeHttpFake()->queueResponse('post', '/v1/checkout/sessions', [
        'error' => ['type' => 'invalid_request_error', 'message' => 'Invalid line item'],
    ], 400);

    stripeGateway()->createCheckoutSession([
        'items' => [['name' => 'X', 'amount' => 100]],
        'returnUrl' => 'https://app.notfy.com/return',
    ]);
})->throws(StripeApiException::class, 'Invalid line item');

it('translates a Stripe API error into a StripeApiException when creating a billing portal session', function () {
    stripeHttpFake()->queueResponse('post', '/v1/billing_portal/sessions', [
        'error' => ['type' => 'invalid_request_error', 'message' => 'No such customer'],
    ], 404);

    stripeGateway()->createBillingPortalSession('cus_missing', 'https://app.notfy.com/return');
})->throws(StripeApiException::class, 'No such customer');

it('creates a billing portal session', function () {
    stripeHttpFake()->queueResponse('post', '/v1/billing_portal/sessions', [
        'id' => 'bps_1',
        'object' => 'billing_portal.session',
        'url' => 'https://billing.stripe.com/session/1',
    ]);

    $session = stripeGateway()->createBillingPortalSession('cus_123', 'https://app.notfy.com/return');

    expect($session['url'])->toBe('https://billing.stripe.com/session/1');

    $params = stripeHttpFake()->requests[0]['params'];
    expect($params['customer'])->toBe('cus_123')
        ->and($params['return_url'])->toBe('https://app.notfy.com/return');
});

it('charges in the currency of the input and maps international payment methods', function () {
    stripeHttpFake()->queueResponse('post', '/v1/payment_intents', ['id' => 'pi_eur', 'object' => 'payment_intent', 'status' => 'requires_action']);

    stripeGateway()->createCharge(new ChargeInput(
        customerExternalId: 'cus_1',
        amountCents: 4200,
        dueDate: '2026-10-20',
        paymentMethod: PaymentMethod::SepaDebit,
        description: 'Invoice',
        externalReference: 'invoice-eur',
        currency: 'EUR',
    ));

    $request = stripeHttpFake()->requests[0];

    expect($request['params']['currency'])->toBe('eur')
        ->and($request['params']['payment_method_types'])->toBe(['sepa_debit']);

    stripeHttpFake()->queueResponse('post', '/v1/payment_intents', ['id' => 'pi_ach', 'object' => 'payment_intent', 'status' => 'requires_action']);

    stripeGateway()->createCharge(chargeInputWithType(PaymentMethod::Ach, 'invoice-ach'));

    expect(stripeHttpFake()->requests[1]['params']['payment_method_types'])->toBe(['us_bank_account']);
});

it('sends the address and document type of an international customer', function () {
    stripeHttpFake()->queueResponse('post', '/v1/customers', ['id' => 'cus_us', 'object' => 'customer']);

    stripeGateway()->createCustomer(new CustomerInput(
        name: 'Acme Inc',
        document: '12-3456789',
        email: 'billing@acme.test',
        externalReference: 'cus-us-1',
        postalCode: '94105',
        country: 'US',
        documentType: 'ein',
        addressLine: '1 Market St',
        city: 'San Francisco',
        state: 'CA',
    ));

    $params = stripeHttpFake()->requests[0]['params'];

    expect($params['address'])->toBe([
        'country' => 'US',
        'line1' => '1 Market St',
        'city' => 'San Francisco',
        'state' => 'CA',
        'postal_code' => '94105',
    ])->and($params['metadata']['document_type'])->toBe('ein');
});
