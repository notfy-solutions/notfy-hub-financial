<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Exceptions;

use InvalidArgumentException;
use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;

/**
 * O gateway resolvido não atende o país, a moeda, o meio de pagamento ou a
 * capacidade pedidos.
 */
final class UnsupportedGatewayRequestException extends InvalidArgumentException
{
    public static function forCountry(string $country, string $gateway): self
    {
        return new self("O gateway '{$gateway}' não atende o país '{$country}'.");
    }

    public static function forCurrency(string $currency, string $gateway): self
    {
        return new self("O gateway '{$gateway}' não cobra na moeda '{$currency}'.");
    }

    public static function forPaymentMethod(PaymentMethod $method, string $gateway): self
    {
        return new self("O gateway '{$gateway}' não aceita o meio de pagamento '{$method->value}'.");
    }

    public static function forCapability(Capability $capability, string $gateway): self
    {
        return new self("O gateway '{$gateway}' não tem a capacidade '{$capability->value}'.");
    }
}
