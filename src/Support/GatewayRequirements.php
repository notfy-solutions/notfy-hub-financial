<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\Exceptions\UnsupportedGatewayRequestException;

/** Regras compartilhadas: o gateway atende país, moeda, método e capacidade pedidos? */
final class GatewayRequirements
{
    /**
     * @throws UnsupportedGatewayRequestException
     */
    public static function assertServes(
        BillingGateway $gateway,
        GatewayContext $context,
        ?PaymentMethod $method = null,
        ?Capability $capability = null,
    ): void {
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
    }

    public static function servesCountry(BillingGateway $gateway, string $country): bool
    {
        $countries = $gateway->supportedCountries();

        return in_array($country, $countries, true) || in_array('*', $countries, true);
    }

    public static function servesCurrency(BillingGateway $gateway, string $currency): bool
    {
        $currencies = $gateway->supportedCurrencies();

        return in_array(strtoupper($currency), $currencies, true) || in_array('*', $currencies, true);
    }
}
