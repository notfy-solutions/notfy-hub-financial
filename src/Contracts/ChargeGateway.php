<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;

interface ChargeGateway
{
    public function createCharge(ChargeInput $input): ChargeResult;

    public function fetchCharge(string $externalId): ChargeResult;

    public function refundCharge(string $externalId, ?int $amountCents = null): bool;

    public function cancelCharge(string $externalId): bool;
}
