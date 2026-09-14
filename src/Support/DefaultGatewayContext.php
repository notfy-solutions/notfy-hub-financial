<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

final class DefaultGatewayContext implements GatewayContext
{
    public function __construct(
        private readonly ?string $preferredGateway = null,
        private readonly string $country = 'BR',
        private readonly string $paymentType = 'any',
    ) {}

    public static function make(
        ?string $preferredGateway = null,
        string $country = 'BR',
        string $paymentType = 'any',
    ): self {
        return new self($preferredGateway, $country, $paymentType);
    }

    public function getPreferredGateway(): ?string
    {
        return $this->preferredGateway;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getPaymentType(): string
    {
        return $this->paymentType;
    }
}
