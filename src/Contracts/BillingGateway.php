<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\DTO\CustomerResult;

/**
 * Gateway de cobrança agnóstico de provedor. Implementações devem ser
 * idempotentes (usar externalReference como chave estável) e nunca vazar
 * detalhes específicos do provedor para a camada de aplicação.
 */
interface BillingGateway
{
    public function createCustomer(CustomerInput $input): CustomerResult;

    public function updateCustomer(string $externalId, CustomerInput $input): CustomerResult;

    public function deleteCustomer(string $externalId): bool;

    public function createCharge(ChargeInput $input): ChargeResult;

    public function fetchCharge(string $externalId): ChargeResult;

    public function refundCharge(string $externalId, ?int $amountCents = null): bool;

    public function cancelCharge(string $externalId): bool;

    /**
     * @param  array<string, mixed>  $params
     * @return array{id: ?string, url: ?string, raw: array<string, mixed>}
     */
    public function createCheckoutSession(array $params): array;

    /**
     * @return array{url: ?string, raw: array<string, mixed>}
     */
    public function createBillingPortalSession(string $customerExternalId, string $returnUrl): array;
}
