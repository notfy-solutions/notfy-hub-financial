<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Fake;

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Contracts\StatementGateway;
use Notfy\HubFinancial\Contracts\SubscriptionGateway;
use Notfy\HubFinancial\Contracts\WebhookHandler;
use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\ChargeStatus;
use Notfy\HubFinancial\Domain\Enums\PaymentEventType;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\DTO\AccountBalance;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\DTO\CustomerResult;
use Notfy\HubFinancial\DTO\StatementEntry;
use Notfy\HubFinancial\DTO\StatementQuery;
use Notfy\HubFinancial\Support\ApiPayload;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Webhooks\PaymentEvent;

/**
 * Gateway em memória para testes e como referência mínima de um driver novo.
 * Implementa todos os contratos sem HTTP e atende qualquer país e moeda.
 * Use forceChargeStatus() para simular transições de status em testes.
 */
final class FakeGateway implements BillingGateway, StatementGateway, SubscriptionGateway, WebhookHandler
{
    /** @var array<string, array<string, mixed>> */
    private array $customers = [];

    /** @var array<string, array{id: string, status: ChargeStatus, input: ChargeInput|null}> */
    private array $charges = [];

    /** @var array<string, array<string, mixed>> */
    private array $subscriptions = [];

    /** @var list<StatementEntry> */
    private array $statement = [];

    private int $balanceCents = 0;

    private string $balanceCurrency = 'BRL';

    // ─── DescribesGateway ────────────────────────────────────────────────────

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return Capability::cases();
    }

    /**
     * @return list<string>
     */
    public function supportedCountries(): array
    {
        return ['*'];
    }

    /**
     * @return list<string>
     */
    public function supportedCurrencies(): array
    {
        return ['*'];
    }

    /**
     * @return list<PaymentMethod>
     */
    public function supportedPaymentMethods(): array
    {
        return PaymentMethod::cases();
    }

    // ─── CustomerGateway ─────────────────────────────────────────────────────

    public function createCustomer(CustomerInput $input): CustomerResult
    {
        $id = 'fake_cus_'.($input->externalReference ?? uniqid());
        $this->customers[$id] = ['id' => $id, 'input' => $input];

        return new CustomerResult(externalId: $id);
    }

    public function updateCustomer(string $externalId, CustomerInput $input): CustomerResult
    {
        $this->customers[$externalId] = ['id' => $externalId, 'input' => $input];

        return new CustomerResult(externalId: $externalId);
    }

    public function deleteCustomer(string $externalId): bool
    {
        unset($this->customers[$externalId]);

        return true;
    }

    // ─── ChargeGateway ───────────────────────────────────────────────────────

    public function createCharge(ChargeInput $input): ChargeResult
    {
        $id = 'fake_pay_'.$input->externalReference;
        $this->charges[$id] = ['id' => $id, 'status' => ChargeStatus::Pending, 'input' => $input];

        return new ChargeResult(externalId: $id, status: ChargeStatus::Pending);
    }

    public function fetchCharge(string $externalId): ChargeResult
    {
        return new ChargeResult(
            externalId: $externalId,
            status: $this->charges[$externalId]['status'] ?? ChargeStatus::Pending,
        );
    }

    public function refundCharge(string $externalId, ?int $amountCents = null): bool
    {
        $this->transitionCharge($externalId, ChargeStatus::Refunded);

        return true;
    }

    public function cancelCharge(string $externalId): bool
    {
        $this->transitionCharge($externalId, ChargeStatus::Deleted);

        return true;
    }

    // ─── CheckoutGateway ─────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $params
     * @return array{id: ?string, url: ?string, raw: array<string, mixed>}
     */
    public function createCheckoutSession(array $params): array
    {
        return ['id' => 'fake_checkout_1', 'url' => 'https://fake.gateway/checkout/1', 'raw' => []];
    }

    /**
     * @return array{url: ?string, raw: array<string, mixed>}
     */
    public function createBillingPortalSession(string $customerExternalId, string $returnUrl): array
    {
        return ['url' => 'https://fake.gateway/portal/1', 'raw' => []];
    }

    // ─── SubscriptionGateway ─────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createSubscription(array $payload): array
    {
        $id = 'fake_sub_'.uniqid();
        $this->subscriptions[$id] = array_merge($payload, ['id' => $id, 'status' => 'ACTIVE']);

        return $this->subscriptions[$id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateSubscription(string $externalId, array $payload): array
    {
        $this->subscriptions[$externalId] = array_merge(
            $this->subscriptions[$externalId] ?? ['id' => $externalId],
            $payload,
        );

        return $this->subscriptions[$externalId];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchSubscription(string $externalId): ?array
    {
        return $this->subscriptions[$externalId] ?? null;
    }

    public function cancelSubscription(string $externalId): bool
    {
        if (isset($this->subscriptions[$externalId])) {
            $this->subscriptions[$externalId]['status'] = 'INACTIVE';
        }

        return true;
    }

    // ─── StatementGateway ────────────────────────────────────────────────────

    /**
     * @return list<StatementEntry>
     */
    public function fetchStatement(StatementQuery $query): array
    {
        return array_values(array_filter(
            $this->statement,
            static fn (StatementEntry $entry): bool => $entry->date >= $query->from && $entry->date <= $query->to,
        ));
    }

    public function fetchBalance(?string $accountId = null): AccountBalance
    {
        return new AccountBalance($this->balanceCents, $this->balanceCurrency);
    }

    // ─── WebhookHandler ──────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $headers
     */
    public function verify(string $rawBody, array $headers, GatewayAccount $account): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload): PaymentEvent
    {
        return new PaymentEvent(
            type: PaymentEventType::Unknown,
            chargeId: ApiPayload::stringOrDefault($payload['id'] ?? null, ''),
            externalReference: null,
            amountPaidCents: null,
            feeCents: null,
            paidAt: null,
            provider: 'fake',
            eventId: ApiPayload::stringOrDefault($payload['id'] ?? null, uniqid()),
            raw: $payload,
        );
    }

    // ─── Helpers para testes ─────────────────────────────────────────────────

    public function forceChargeStatus(string $externalId, ChargeStatus $status): void
    {
        $this->transitionCharge($externalId, $status);
    }

    /**
     * Define o extrato e o saldo que o gateway devolve.
     *
     * @param  list<StatementEntry>  $entries
     */
    public function seedStatement(array $entries, int $balanceCents = 0, string $currency = 'BRL'): void
    {
        $this->statement = $entries;
        $this->balanceCents = $balanceCents;
        $this->balanceCurrency = $currency;
    }

    /**
     * @return array<string, array{id: string, status: ChargeStatus, input: ChargeInput|null}>
     */
    public function getCharges(): array
    {
        return $this->charges;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getCustomers(): array
    {
        return $this->customers;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getSubscriptions(): array
    {
        return $this->subscriptions;
    }

    private function transitionCharge(string $externalId, ChargeStatus $status): void
    {
        if (isset($this->charges[$externalId])) {
            $this->charges[$externalId]['status'] = $status;
        }
    }
}
