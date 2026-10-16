<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\Exceptions\UnsupportedGatewayRequestException;

interface GatewayResolver
{
    public function resolve(?GatewayContext $context = null): BillingGateway;

    /**
     * Resolve o gateway e confirma que ele atende o país e a moeda do
     * contexto, o meio de pagamento e a capacidade pedidos.
     *
     * @throws UnsupportedGatewayRequestException
     */
    public function resolveFor(
        ?GatewayContext $context = null,
        ?PaymentMethod $method = null,
        ?Capability $capability = null,
    ): BillingGateway;

    /**
     * O gateway do contexto vem primeiro, seguido dos demais que atendem o
     * país (candidatos a fallback).
     *
     * @return array<int, BillingGateway>
     */
    public function getAllAvailable(GatewayContext $context): array;

    /**
     * Há rota configurada para o país, com gateway existente? Olha só a
     * configuração (não instancia o gateway), então é seguro chamar da UI.
     */
    public function supportsCountry(string $country): bool;
}
