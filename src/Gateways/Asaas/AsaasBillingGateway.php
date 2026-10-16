<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Asaas;

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Contracts\SubscriptionGateway;
use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\ChargeStatus;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\DTO\CustomerResult;
use Notfy\HubFinancial\Exceptions\AsaasApiException;
use Notfy\HubFinancial\Exceptions\UnsupportedGatewayRequestException;
use Notfy\HubFinancial\Support\ApiPayload;
use RuntimeException;

final class AsaasBillingGateway implements BillingGateway, SubscriptionGateway
{
    public function __construct(
        private readonly AsaasHttpClient $client,
    ) {}

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return [
            Capability::BankSlip,
            Capability::Pix,
            Capability::Card,
            Capability::HybridBoletoPix,
            Capability::Subscription,
            Capability::Checkout,
            Capability::BillingPortal,
            Capability::Refund,
            Capability::Webhook,
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedCountries(): array
    {
        return ['BR'];
    }

    /**
     * @return list<string>
     */
    public function supportedCurrencies(): array
    {
        return ['BRL'];
    }

    /**
     * @return list<PaymentMethod>
     */
    public function supportedPaymentMethods(): array
    {
        return [
            PaymentMethod::Boleto,
            PaymentMethod::Pix,
            PaymentMethod::Card,
            PaymentMethod::Hybrid,
            PaymentMethod::Undefined,
        ];
    }

    public function createCustomer(CustomerInput $input): CustomerResult
    {
        $payload = $this->filter([
            'name' => $input->name,
            'cpfCnpj' => preg_replace('/\D/', '', $input->document),
            'email' => $input->email,
            'mobilePhone' => $input->phone,
            'externalReference' => $input->externalReference,
            'postalCode' => $input->postalCode,
            'addressNumber' => $input->addressNumber,
        ]);

        $customer = $this->client->post('customers', $payload);

        return new CustomerResult(
            externalId: ApiPayload::requiredString($customer['id'] ?? null, 'id'),
            raw: $customer,
        );
    }

    public function updateCustomer(string $externalId, CustomerInput $input): CustomerResult
    {
        $payload = $this->filter([
            'name' => $input->name,
            'cpfCnpj' => preg_replace('/\D/', '', $input->document),
            'email' => $input->email,
            'mobilePhone' => $input->phone,
            'externalReference' => $input->externalReference,
            'postalCode' => $input->postalCode,
            'addressNumber' => $input->addressNumber,
        ]);

        $customer = $this->client->put("customers/{$externalId}", $payload);

        return new CustomerResult(
            externalId: ApiPayload::requiredString($customer['id'] ?? null, 'id'),
            raw: $customer,
        );
    }

    public function deleteCustomer(string $externalId): bool
    {
        try {
            $result = $this->client->delete("customers/{$externalId}");

            return (bool) ($result['deleted'] ?? true);
        } catch (AsaasApiException) {
            return false;
        }
    }

    public function createCharge(ChargeInput $input): ChargeResult
    {
        if ($input->currency !== null && strtoupper($input->currency) !== 'BRL') {
            throw UnsupportedGatewayRequestException::forCurrency(strtoupper($input->currency), self::class);
        }

        if (! in_array($input->paymentMethod, $this->supportedPaymentMethods(), true)) {
            throw UnsupportedGatewayRequestException::forPaymentMethod($input->paymentMethod, self::class);
        }

        $payload = array_merge($this->filter([
            'customer' => $input->customerExternalId,
            'billingType' => $input->paymentMethod->value,
            'value' => round($input->amountCents / 100, 2),
            'dueDate' => $input->dueDate,
            'description' => $input->description,
            'externalReference' => $input->externalReference,
            'creditCardToken' => $input->cardToken,
            'remoteIp' => $input->remoteIp,
        ]), $input->extra);

        $payment = $this->client->post('payments', $payload);

        return $this->toChargeResult($payment);
    }

    public function fetchCharge(string $externalId): ChargeResult
    {
        $payment = $this->client->get("payments/{$externalId}");

        return $this->toChargeResult($payment);
    }

    public function refundCharge(string $externalId, ?int $amountCents = null): bool
    {
        try {
            $payload = $amountCents !== null ? ['value' => round($amountCents / 100, 2)] : [];
            $this->client->post("payments/{$externalId}/refund", $payload);

            return true;
        } catch (AsaasApiException) {
            return false;
        }
    }

    public function cancelCharge(string $externalId): bool
    {
        try {
            $result = $this->client->delete("payments/{$externalId}");

            return (bool) ($result['deleted'] ?? true);
        } catch (AsaasApiException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{id: ?string, url: ?string, raw: array<string, mixed>}
     */
    public function createCheckoutSession(array $params): array
    {
        $cycle = $params['cycle'] ?? null;
        $isRecurring = $cycle !== null && $cycle !== '';

        $payload = $this->filter([
            'billingTypes' => $params['billingTypes'] ?? ['CREDIT_CARD', 'PIX', 'BOLETO'],
            'chargeTypes' => [$isRecurring ? 'RECURRENT' : 'DETACHED'],
            'items' => $this->checkoutItems($params['items'] ?? null),
            'callback' => $this->filter([
                'successUrl' => $params['returnUrl'] ?? $params['successUrl'] ?? null,
                'cancelUrl' => $params['cancelUrl'] ?? $params['returnUrl'] ?? null,
            ]),
        ]);

        $payload = $this->applyCheckoutExtras($payload, $params, $isRecurring, $cycle);

        $checkout = $this->client->post('checkouts', $payload);

        return [
            'id' => ApiPayload::optionalString($checkout['id'] ?? null),
            'url' => ApiPayload::optionalString($checkout['link'] ?? null),
            'raw' => $checkout,
        ];
    }

    /**
     * Cria uma assinatura recorrente nativa do Asaas (fora do fluxo de checkout).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createSubscription(array $payload): array
    {
        return $this->client->post('subscriptions', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateSubscription(string $externalId, array $payload): array
    {
        return $this->client->put("subscriptions/{$externalId}", $payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchSubscription(string $externalId): ?array
    {
        try {
            return $this->client->get("subscriptions/{$externalId}");
        } catch (AsaasApiException) {
            return null;
        }
    }

    public function cancelSubscription(string $externalId): bool
    {
        try {
            $result = $this->client->delete("subscriptions/{$externalId}");

            return (bool) ($result['deleted'] ?? true);
        } catch (AsaasApiException) {
            return false;
        }
    }

    /**
     * @return array{url: ?string, raw: array<string, mixed>}
     */
    public function createBillingPortalSession(string $customerExternalId, string $returnUrl): array
    {
        // Asaas não tem um "billing portal" nativo (self-service) como o Stripe.
        // Workaround: localiza a assinatura ativa do cliente e gera um novo link
        // de checkout equivalente, permitindo trocar cartão / regularizar pagamento.
        $subscriptions = $this->client->get('subscriptions', [
            'customer' => $customerExternalId,
            'status' => 'ACTIVE',
        ]);

        $subscriptionList = ApiPayload::toListOfArrays($subscriptions['data'] ?? null);
        $subscription = $subscriptionList[0] ?? null;

        if ($subscription === null) {
            throw new RuntimeException('Nenhuma assinatura ativa encontrada para gerar sessão de billing portal.');
        }

        $subscriptionItem = [
            'name' => ApiPayload::stringOrDefault($subscription['description'] ?? null, 'Assinatura'),
            'amount' => (int) round(ApiPayload::toFloat($subscription['value'] ?? null) * 100),
            'quantity' => 1,
        ];

        $session = $this->createCheckoutSession([
            'items' => [$subscriptionItem],
            'cycle' => ApiPayload::stringOrDefault($subscription['cycle'] ?? null, 'MONTHLY'),
            'returnUrl' => $returnUrl,
            'customerId' => $customerExternalId,
        ]);

        return [
            'url' => $session['url'],
            'raw' => $session['raw'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function toChargeResult(array $payment): ChargeResult
    {
        return new ChargeResult(
            externalId: ApiPayload::requiredString($payment['id'] ?? null, 'id'),
            status: $this->mapStatus(ApiPayload::stringOrDefault($payment['status'] ?? null, 'PENDING')),
            invoiceUrl: ApiPayload::optionalString($payment['invoiceUrl'] ?? null),
            bankSlipUrl: ApiPayload::optionalString($payment['bankSlipUrl'] ?? null),
            pixCopyPaste: ApiPayload::optionalString($payment['pixCopyPaste'] ?? null),
            raw: $payment,
        );
    }

    private function mapStatus(string $asaasStatus): ChargeStatus
    {
        return match ($asaasStatus) {
            'CONFIRMED' => ChargeStatus::Confirmed,
            'RECEIVED', 'RECEIVED_IN_CASH' => ChargeStatus::Received,
            'OVERDUE' => ChargeStatus::Overdue,
            'REFUNDED', 'REFUND_REQUESTED', 'REFUND_IN_PROGRESS', 'PARTIALLY_REFUNDED' => ChargeStatus::Refunded,
            'DELETED' => ChargeStatus::Deleted,
            default => ChargeStatus::Pending,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filter(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<array{name: string, description: string, quantity: int, value: float}>
     */
    private function checkoutItems(mixed $itemsParam): array
    {
        $items = [];

        foreach (ApiPayload::toListOfArrays($itemsParam) as $item) {
            $name = ApiPayload::stringOrDefault($item['name'] ?? null, 'Produto');

            $items[] = [
                'name' => $name,
                'description' => ApiPayload::stringOrDefault($item['description'] ?? null, $name),
                'quantity' => ApiPayload::toInt($item['quantity'] ?? null, 1),
                'value' => round(ApiPayload::toFloat($item['amount'] ?? null) / 100, 2),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function applyCheckoutExtras(array $payload, array $params, bool $isRecurring, mixed $cycle): array
    {
        $customerId = ApiPayload::optionalString($params['customerId'] ?? null);
        $customerData = $params['customerData'] ?? null;

        if ($customerId !== null && $customerId !== '') {
            $payload['customer'] = $customerId;
        } elseif ($customerData !== null && $customerData !== '') {
            $payload['customerData'] = $customerData;
        }

        if ($isRecurring) {
            $payload['subscription'] = ['cycle' => strtoupper(ApiPayload::stringOrDefault($cycle, ''))];
        }

        $externalReference = ApiPayload::optionalString($params['externalReference'] ?? null);

        if ($externalReference !== null && $externalReference !== '') {
            $payload['externalReference'] = $externalReference;
        }

        return $payload;
    }
}
