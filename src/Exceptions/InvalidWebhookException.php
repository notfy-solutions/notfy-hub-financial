<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Exceptions;

use InvalidArgumentException;

final class InvalidWebhookException extends InvalidArgumentException
{
    public static function unknownProvider(string $provider): self
    {
        return new self("Nenhum handler de webhook registrado para '{$provider}'.");
    }

    public static function invalidSignature(string $provider): self
    {
        return new self("Assinatura/token do webhook de '{$provider}' inválido.");
    }

    public static function malformedBody(string $provider): self
    {
        return new self("Corpo do webhook de '{$provider}' não é um JSON válido.");
    }
}
