<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use Notfy\HubFinancial\Contracts\BillingGateway;

interface GatewayResolver
{
    public function resolve(?GatewayContext $context = null): BillingGateway;

    /**
     * @return BillingGateway[]
     */
    public function getAllAvailable(GatewayContext $context): array;
}
