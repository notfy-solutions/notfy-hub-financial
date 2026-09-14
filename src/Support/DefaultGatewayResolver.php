<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use InvalidArgumentException;
use Notfy\HubFinancial\Contracts\BillingGateway;

/**
 * Resolve qual BillingGateway usar com base na preferência do cliente ou em
 * uma tabela de roteamento por país/tipo de pagamento. Não depende de nenhum
 * model Eloquent — a aplicação consumidora monta o GatewayContext a partir
 * dos próprios dados (ex.: Client::preferred_gateway).
 */
final class DefaultGatewayResolver implements GatewayResolver
{
    /** @var array<string, BillingGateway> */
    private array $gatewayInstances = [];

    /**
     * @param  array<string, array{class: class-string<BillingGateway>}>  $gateways
     * @param  array<string, array<string, string>>  $routing
     */
    public function __construct(
        private readonly array $gateways,
        private readonly array $routing,
    ) {}

    public function resolve(?GatewayContext $context = null): BillingGateway
    {
        $context ??= DefaultGatewayContext::make();

        if ($context->getPreferredGateway() !== null && $this->hasGateway($context->getPreferredGateway())) {
            return $this->getGateway($context->getPreferredGateway());
        }

        $routing = $this->routing[$context->getCountry()] ?? $this->routing['*'] ?? [];
        $type = $context->getPaymentType();

        $gatewayName = $type !== 'any' && isset($routing[$type])
            ? $routing[$type]
            : $routing['default'] ?? array_key_first($this->gateways);

        if ($gatewayName === null) {
            throw new InvalidArgumentException('Nenhum gateway configurado.');
        }

        return $this->getGateway($gatewayName);
    }

    /**
     * @return array<int, BillingGateway>
     */
    public function getAllAvailable(GatewayContext $context): array
    {
        $primary = $this->resolve($context);
        $gateways = [$primary];

        foreach (array_keys($this->gateways) as $name) {
            $instance = $this->getGateway($name);
            if ($instance !== $primary) {
                $gateways[] = $instance;
            }
        }

        return $gateways;
    }

    private function getGateway(string $name): BillingGateway
    {
        if (! isset($this->gatewayInstances[$name])) {
            if (! $this->hasGateway($name)) {
                throw new InvalidArgumentException("Gateway '{$name}' não configurado");
            }

            $this->gatewayInstances[$name] = app($this->gateways[$name]['class']);
        }

        return $this->gatewayInstances[$name];
    }

    private function hasGateway(string $name): bool
    {
        return isset($this->gateways[$name]);
    }
}
