<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\DTO\CustomerResult;

interface CustomerGateway
{
    public function createCustomer(CustomerInput $input): CustomerResult;

    public function updateCustomer(string $externalId, CustomerInput $input): CustomerResult;

    public function deleteCustomer(string $externalId): bool;
}
