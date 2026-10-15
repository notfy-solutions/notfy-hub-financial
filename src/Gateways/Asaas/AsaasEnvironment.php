<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Asaas;

enum AsaasEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://api-sandbox.asaas.com/v3',
            self::Production => 'https://api.asaas.com/v3',
        };
    }

    public static function fromConfig(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Sandbox) : self::Sandbox;
    }
}
