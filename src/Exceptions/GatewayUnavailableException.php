<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Exceptions;

/** Circuito aberto: o gateway está sendo poupado após falhas consecutivas. */
final class GatewayUnavailableException extends GatewayException
{
    public static function circuitOpen(string $gateway): self
    {
        return new self("Gateway '{$gateway}' temporariamente indisponível (circuit breaker aberto).", 503);
    }
}
