<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Asaas;

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\DTO\CustomerResult;
use Notfy\HubFinancial\Exceptions\AsaasApiException;
use RuntimeException;

final class AsaasBillingGateway implements BillingGateway
{
    public function __construct(
        private readonly AsaasHttpClient $client,
    ) {}

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

        return new CustomerResult(externalId: (string) $customer['id'], raw: $customer);
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

        return new CustomerResult(externalId: (string) $customer['id'], raw: $customer);
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
        $payload = array_merge($this->filter([
            'customer' => $input->customerExternalId,
            'billingType' => $input->billingType,
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

    public function createCheckoutSession(array $params): array
    {
        $items = [];
        foreach ((array) ($params['items'] ?? []) as $item) {
            $items[] = [
                'name' => $item['name'] ?? 'Produto',
                'description' => $item['description'] ?? ($item['name'] ?? 'Produto'),
                'quantity' => $item['quantity'] ?? 1,
                'value' => round((float) ($item['amount'] ?? 0) / 100, 2),
            ];
        }

        $isRecurring = ! empty($params['cycle']);

        $payload = $this->filter([
            'billingTypes' => $params['billingTypes'] ?? ['CREDIT_CARD', 'PIX', 'BOLETO'],
            'chargeTypes' => [$isRecurring ? 'RECURRENT' : 'DETACHED'],
            'items' => $items,
            'callback' => $this->filter([
                'successUrl' => $params['returnUrl'] ?? $params['successUrl'] ?? null,
                'cancelUrl' => $params['cancelUrl'] ?? $params['returnUrl'] ?? null,
            ]),
        ]);

        if (! empty($params['customerId'])) {
            $payload['customer'] = $params['customerId'];
        } elseif (! empty($params['customerData'])) {
            $payload['customerData'] = $params['customerData'];
        }

        if ($isRecurring) {
            $payload['subscription'] = ['cycle' => strtoupper((string) $params['cycle'])];
        }

        if (! empty($params['externalReference'])) {
            $payload['externalReference'] = $params['externalReference'];
        }

        $checkout = $this->client->post('checkouts', $payload);

        return [
            'id' => $checkout['id'] ?? null,
            'url' => $checkout['link'] ?? null,
            'raw' => $checkout,
        ];
    }

    public function createBillingPortalSession(string $customerExternalId, string $returnUrl): array
    {
        // Asaas não tem um "billing portal" nativo (self-service) como o Stripe.
        // Workaround: localiza a assinatura ativa do cliente e gera um novo link
        // de checkout equivalente, permitindo trocar cartão / regularizar pagamento.
        $subscriptions = $this->client->get('subscriptions', [
            'customer' => $customerExternalId,
            'status' => 'ACTIVE',
        ]);

        $subscription = $subscriptions['data'][0] ?? null;

        if ($subscription === null) {
            throw new RuntimeException('Nenhuma assinatura ativa encontrada para gerar sessão de billing portal.');
        }

        $session = $this->createCheckoutSession([
            'items' => [[
                'name' => $subscription['description'] ?? 'Assinatura',
                'amount' => (int) round(((float) $subscription['value']) * 100),
                'quantity' => 1,
            ]],
            'cycle' => $subscription['cycle'] ?? 'MONTHLY',
            'returnUrl' => $returnUrl,
            'customerId' => $customerExternalId,
        ]);

        return [
            'url' => $session['url'],
            'raw' => $session['raw'],
        ];
    }

    private function toChargeResult(array $payment): ChargeResult
    {
        return new ChargeResult(
            externalId: (string) $payment['id'],
            status: $this->mapStatus((string) ($payment['status'] ?? 'PENDING')),
            invoiceUrl: $payment['invoiceUrl'] ?? null,
            bankSlipUrl: $payment['bankSlipUrl'] ?? null,
            pixCopyPaste: $payment['pixCopyPaste'] ?? null,
            raw: $payment,
        );
    }

    private function mapStatus(string $asaasStatus): string
    {
        return match ($asaasStatus) {
            'CONFIRMED' => 'CONFIRMED',
            'RECEIVED', 'RECEIVED_IN_CASH' => 'RECEIVED',
            'OVERDUE' => 'OVERDUE',
            'REFUNDED', 'REFUND_REQUESTED', 'REFUND_IN_PROGRESS', 'PARTIALLY_REFUNDED' => 'REFUNDED',
            'DELETED' => 'DELETED',
            default => 'PENDING',
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
}
