<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use InvalidArgumentException;
use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\Exceptions\UnsupportedGatewayRequestException;

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

    public function resolveFor(
        ?GatewayContext $context = null,
        ?PaymentMethod $method = null,
        ?Capability $capability = null,
    ): BillingGateway {
        $context ??= DefaultGatewayContext::make();
        $gateway = $this->resolve($context);
        $name = $gateway::class;

        if (! self::servesCountry($gateway, $context->getCountry())) {
            throw UnsupportedGatewayRequestException::forCountry($context->getCountry(), $name);
        }

        $currency = $context->getCurrency();

        if ($currency !== null && ! self::servesCurrency($gateway, $currency)) {
            throw UnsupportedGatewayRequestException::forCurrency(strtoupper($currency), $name);
        }

        if ($method !== null && ! in_array($method, $gateway->supportedPaymentMethods(), true)) {
            throw UnsupportedGatewayRequestException::forPaymentMethod($method, $name);
        }

        if ($capability !== null && ! in_array($capability, $gateway->capabilities(), true)) {
            throw UnsupportedGatewayRequestException::forCapability($capability, $name);
        }

        return $gateway;
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

            if ($instance !== $primary && self::servesCountry($instance, $context->getCountry())) {
                $gateways[] = $instance;
            }
        }

        return $gateways;
    }

    public function supportsCountry(string $country): bool
    {
        $routing = $this->routing[$country] ?? $this->routing['*'] ?? [];
        $name = $routing['default'] ?? array_key_first($this->gateways);

        return $name !== null && $this->hasGateway($name);
    }

    private static function servesCountry(BillingGateway $gateway, string $country): bool
    {
        $countries = $gateway->supportedCountries();

        return in_array($country, $countries, true) || in_array('*', $countries, true);
    }

    private static function servesCurrency(BillingGateway $gateway, string $currency): bool
    {
        $currencies = $gateway->supportedCurrencies();

        return in_array(strtoupper($currency), $currencies, true) || in_array('*', $currencies, true);
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
