<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

interface GatewayContext
{
    public function getPreferredGateway(): ?string;

    public function getCountry(): string;

    public function getPaymentType(): string; // card, pix, boleto, any

    /** ISO 4217 da cobrança, quando já conhecida; null deixa o gateway usar a moeda do país. */
    public function getCurrency(): ?string;
}
