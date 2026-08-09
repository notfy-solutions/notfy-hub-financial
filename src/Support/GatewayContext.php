<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

interface GatewayContext
{
    public function getPreferredGateway(): ?string;

    public function getCountry(): string;

    public function getPaymentType(): string; // card, pix, boleto, any
}
