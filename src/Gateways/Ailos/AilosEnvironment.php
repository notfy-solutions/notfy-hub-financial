<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Ailos;

enum AilosEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /**
     * Homologação: host do portal de desenvolvedor citado no manual. A URL de
     * produção é entregue pela cooperativa filiada, por isso não há padrão:
     * informe `base_url` na conta.
     */
    public function defaultBaseUrl(): ?string
    {
        return match ($this) {
            self::Sandbox => 'https://apihml.ailos.coop.br/ailos/cobranca/api',
            self::Production => null,
        };
    }

    public static function fromConfig(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Sandbox) : self::Sandbox;
    }
}
