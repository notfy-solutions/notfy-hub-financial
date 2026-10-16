<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

final class DefaultGatewayContext implements GatewayContext
{
    public function __construct(
        private readonly ?string $preferredGateway = null,
        private readonly string $country = 'BR',
        private readonly string $paymentType = 'any',
        private readonly ?string $currency = null,
    ) {}

    public static function make(
        ?string $preferredGateway = null,
        string $country = 'BR',
        string $paymentType = 'any',
        ?string $currency = null,
    ): self {
        return new self($preferredGateway, $country, $paymentType, $currency);
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

    public function getCurrency(): ?string
    {
        return $this->currency;
    }
}
