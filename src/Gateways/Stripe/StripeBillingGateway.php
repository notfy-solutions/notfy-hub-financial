<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Stripe;

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\DTO\CustomerResult;
use Notfy\HubFinancial\Exceptions\StripeApiException;
use Notfy\HubFinancial\Support\ApiPayload;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Cobrança internacional via Stripe (PaymentIntents + Checkout), usando o SDK
 * oficial `stripe/stripe-php` — ao contrário do gateway Asaas (sem SDK
 * mantido pelo provedor), o Stripe disponibiliza um cliente oficial bem
 * testado, então não faz sentido reimplementar serialização/erros/retries
 * na mão. Cobre o mesmo contrato BillingGateway do Asaas — quem decide qual
 * dos dois usar é o GatewayResolver, por país/preferência do cliente, nunca
 * esta classe.
 *
 * `extra` do ChargeInput aceita `currency` (ISO 4217, ex.: "usd", "eur");
 * quando ausente, usa `$defaultCurrency` (config `hub-financial.gateways.
 * stripe.default_currency`).
 *
 * Aliases abaixo espelham (só para os campos que esta classe efetivamente
 * monta) os array-shapes reais que `stripe/stripe-php` declara via docblock
 * para cada `Service::create()/update()` chamado aqui — cada campo é
 * opcional exatamente onde o Stripe também o declara opcional, então o
 * payload filtrado por `filter()` (que remove null/''/[] e torna toda chave
 * potencialmente ausente) sempre satisfaz o contrato real do SDK.
 *
 * @phpstan-type CustomerPayload array{
 *     name?: string, email?: string, phone?: string, metadata?: array<string, string>
 * }
 * @phpstan-type ChargePayload array{
 *     amount: int, currency: string, customer?: string, description?: string,
 *     payment_method_types?: list<string>, payment_method?: string, confirm?: bool,
 *     metadata?: array<string, string>
 * }
 * @phpstan-type RefundPayload array{payment_intent?: string, amount?: int}
 * @phpstan-type CheckoutPriceData array{
 *     currency: string, unit_amount: int, product_data: array{name: string},
 *     recurring?: array{interval: string}
 * }
 * @phpstan-type CheckoutLineItem array{price_data: CheckoutPriceData, quantity: int}
 * @phpstan-type CheckoutPayload array{
 *     mode: string, line_items?: list<CheckoutLineItem>, success_url?: string,
 *     cancel_url?: string, customer?: string, customer_email?: string,
 *     metadata?: array<string, string>
 * }
 */
final class StripeBillingGateway implements BillingGateway
{
    public function __construct(
        private readonly StripeClient $client,
        private readonly string $defaultCurrency = 'usd',
    ) {}

    public function createCustomer(CustomerInput $input): CustomerResult
    {
        $payload = $this->customerPayload($input);

        try {
            $customer = $this->client->customers->create(
                $payload,
                $this->idempotencyOptions($this->idempotencyKey('customer', $input->externalReference)),
            );
        } catch (ApiErrorException $exception) {
            throw $this->translate($exception);
        }

        return new CustomerResult(externalId: $customer->id, raw: ApiPayload::toAssocArray($customer->toArray()));
    }

    public function updateCustomer(string $externalId, CustomerInput $input): CustomerResult
    {
        $payload = $this->customerPayload($input);

        try {
            $customer = $this->client->customers->update($externalId, $payload);
        } catch (ApiErrorException $exception) {
            throw $this->translate($exception);
        }

        return new CustomerResult(externalId: $customer->id, raw: ApiPayload::toAssocArray($customer->toArray()));
    }

    public function deleteCustomer(string $externalId): bool
    {
        try {
            $customer = $this->client->customers->delete($externalId);
            $raw = ApiPayload::toAssocArray($customer->toArray());

            return (bool) ($raw['deleted'] ?? true);
        } catch (ApiErrorException) {
            return false;
        }
    }

    public function createCharge(ChargeInput $input): ChargeResult
    {
        $hasPaymentMethod = $input->cardToken !== null && $input->cardToken !== '';

        $payload = array_merge($this->filter([
            'amount' => $input->amountCents,
            'currency' => ApiPayload::stringOrDefault($input->extra['currency'] ?? null, $this->defaultCurrency),
            'customer' => $input->customerExternalId,
            'description' => $input->description,
            'payment_method_types' => $this->paymentMethodTypes($input->billingType),
            'payment_method' => $input->cardToken,
            'confirm' => $hasPaymentMethod ? true : null,
            'metadata' => $this->filter([
                'billing_type' => $input->billingType,
                'due_date' => $input->dueDate,
                'external_reference' => $input->externalReference,
            ]),
        ]), $input->extra);

        /** @var ChargePayload $payload */
        try {
            $paymentIntent = $this->client->paymentIntents->create(
                $payload,
                $this->idempotencyOptions($this->idempotencyKey('charge', $input->externalReference)),
            );
        } catch (ApiErrorException $exception) {
            throw $this->translate($exception);
        }

        return $this->toChargeResult(ApiPayload::toAssocArray($paymentIntent->toArray()));
    }

    public function fetchCharge(string $externalId): ChargeResult
    {
        try {
            $paymentIntent = $this->client->paymentIntents->retrieve($externalId);
        } catch (ApiErrorException $exception) {
            throw $this->translate($exception);
        }

        return $this->toChargeResult(ApiPayload::toAssocArray($paymentIntent->toArray()));
    }

    public function refundCharge(string $externalId, ?int $amountCents = null): bool
    {
        $payload = $this->filter([
            'payment_intent' => $externalId,
            'amount' => $amountCents,
        ]);

        /** @var RefundPayload $payload */
        try {
            $this->client->refunds->create($payload);

            return true;
        } catch (ApiErrorException) {
            return false;
        }
    }

    public function cancelCharge(string $externalId): bool
    {
        try {
            $this->client->paymentIntents->cancel($externalId);

            return true;
        } catch (ApiErrorException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{id: ?string, url: ?string, raw: array<string, mixed>}
     */
    public function createCheckoutSession(array $params): array
    {
        $cycle = ApiPayload::optionalString($params['cycle'] ?? null);
        $isRecurring = $cycle !== null && $cycle !== '';
        $currency = ApiPayload::stringOrDefault($params['currency'] ?? null, $this->defaultCurrency);
        $customerId = ApiPayload::optionalString($params['customerId'] ?? null);
        $successUrl = ApiPayload::optionalString($params['successUrl'] ?? $params['returnUrl'] ?? null);
        $cancelUrl = ApiPayload::optionalString($params['cancelUrl'] ?? $params['returnUrl'] ?? null);
        $customerEmail = $customerId === null ? ApiPayload::optionalString($params['customerEmail'] ?? null) : null;
        $externalReference = ApiPayload::optionalString($params['externalReference'] ?? null);

        $payload = $this->filter([
            'mode' => $isRecurring ? 'subscription' : 'payment',
            'line_items' => $this->checkoutLineItems($params['items'] ?? null, $currency, $isRecurring, $cycle),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer' => $customerId,
            'customer_email' => $customerEmail,
            'metadata' => $this->filter([
                'external_reference' => $externalReference,
            ]),
        ]);

        /** @var CheckoutPayload $payload */
        try {
            $session = $this->client->checkout->sessions->create($payload);
        } catch (ApiErrorException $exception) {
            throw $this->translate($exception);
        }

        $raw = ApiPayload::toAssocArray($session->toArray());

        return [
            'id' => ApiPayload::optionalString($raw['id'] ?? null),
            'url' => ApiPayload::optionalString($raw['url'] ?? null),
            'raw' => $raw,
        ];
    }

    /**
     * @return array{url: ?string, raw: array<string, mixed>}
     */
    public function createBillingPortalSession(string $customerExternalId, string $returnUrl): array
    {
        try {
            $session = $this->client->billingPortal->sessions->create([
                'customer' => $customerExternalId,
                'return_url' => $returnUrl,
            ]);
        } catch (ApiErrorException $exception) {
            throw $this->translate($exception);
        }

        $raw = ApiPayload::toAssocArray($session->toArray());

        return [
            'url' => ApiPayload::optionalString($raw['url'] ?? null),
            'raw' => $raw,
        ];
    }

    /**
     * @return CustomerPayload
     */
    private function customerPayload(CustomerInput $input): array
    {
        /** @var CustomerPayload */
        return $this->filter([
            'name' => $input->name,
            'email' => $input->email,
            'phone' => $input->phone,
            'metadata' => $this->filter([
                'document' => $input->document,
                'external_reference' => $input->externalReference,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $paymentIntent
     */
    private function toChargeResult(array $paymentIntent): ChargeResult
    {
        $nextAction = $this->extractNextAction($paymentIntent);

        return new ChargeResult(
            externalId: ApiPayload::requiredString($paymentIntent['id'] ?? null, 'id'),
            status: $this->mapStatus(
                ApiPayload::stringOrDefault($paymentIntent['status'] ?? null, 'requires_payment_method'),
            ),
            invoiceUrl: $nextAction['invoiceUrl'],
            bankSlipUrl: $nextAction['bankSlipUrl'],
            pixCopyPaste: $nextAction['pixCopyPaste'],
            raw: $paymentIntent,
        );
    }

    /**
     * Boleto/Pix no Stripe não voltam como campos diretos do PaymentIntent —
     * ficam dentro de `next_action`, específicos de cada payment method.
     *
     * @param  array<string, mixed>  $paymentIntent
     * @return array{invoiceUrl: ?string, bankSlipUrl: ?string, pixCopyPaste: ?string}
     */
    private function extractNextAction(array $paymentIntent): array
    {
        $nextAction = ApiPayload::toAssocArray($paymentIntent['next_action'] ?? null);
        $boleto = ApiPayload::toAssocArray($nextAction['boleto_display_details'] ?? null);
        $pix = ApiPayload::toAssocArray($nextAction['pix_display_qr_code'] ?? null);

        return [
            'invoiceUrl' => ApiPayload::optionalString($boleto['hosted_voucher_url'] ?? null)
                ?? ApiPayload::optionalString($pix['hosted_instructions_url'] ?? null),
            'bankSlipUrl' => ApiPayload::optionalString($boleto['pdf'] ?? null),
            'pixCopyPaste' => ApiPayload::optionalString($pix['data'] ?? null),
        ];
    }

    private function mapStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'succeeded' => 'RECEIVED',
            'requires_capture' => 'CONFIRMED',
            'canceled' => 'DELETED',
            'processing', 'requires_action', 'requires_confirmation', 'requires_payment_method' => 'PENDING',
            default => 'PENDING',
        };
    }

    /**
     * @return list<string>
     */
    private function paymentMethodTypes(string $billingType): array
    {
        return match ($billingType) {
            'BOLETO' => ['boleto'],
            'PIX' => ['pix'],
            default => ['card'],
        };
    }

    private function idempotencyKey(string $operation, ?string $externalReference): ?string
    {
        if ($externalReference === null || $externalReference === '') {
            return null;
        }

        return "{$operation}:{$externalReference}";
    }

    /**
     * @return array{idempotency_key: string}|array{}
     */
    private function idempotencyOptions(?string $idempotencyKey): array
    {
        return $idempotencyKey === null ? [] : ['idempotency_key' => $idempotencyKey];
    }

    private function translate(ApiErrorException $exception): StripeApiException
    {
        return new StripeApiException(
            $exception->getMessage(),
            $exception->getHttpStatus() ?? 0,
            ApiPayload::toAssocArray($exception->getJsonBody()),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filter(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return list<CheckoutLineItem>
     */
    private function checkoutLineItems(mixed $itemsParam, string $currency, bool $isRecurring, ?string $cycle): array
    {
        $items = [];

        foreach (ApiPayload::toListOfArrays($itemsParam) as $item) {
            $priceData = $this->filter([
                'currency' => $currency,
                'unit_amount' => ApiPayload::toInt($item['amount'] ?? null),
                'product_data' => [
                    'name' => ApiPayload::stringOrDefault($item['name'] ?? null, 'Produto'),
                ],
                'recurring' => $isRecurring
                    ? ['interval' => $this->stripeInterval($cycle)]
                    : null,
            ]);

            /** @var CheckoutPriceData $priceData */
            $items[] = [
                'price_data' => $priceData,
                'quantity' => ApiPayload::toInt($item['quantity'] ?? null, 1),
            ];
        }

        return $items;
    }

    private function stripeInterval(?string $cycle): string
    {
        return match (strtoupper((string) $cycle)) {
            'YEARLY', 'ANNUALLY' => 'year',
            'WEEKLY' => 'week',
            default => 'month',
        };
    }
}
