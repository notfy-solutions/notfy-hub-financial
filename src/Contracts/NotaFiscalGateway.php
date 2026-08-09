<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

use Notfy\HubFinancial\DTO\NotaFiscalInput;
use Notfy\HubFinancial\DTO\NotaFiscalResult;

interface NotaFiscalGateway
{
    public function emit(NotaFiscalInput $input): NotaFiscalResult;

    public function getStatus(string $externalId): NotaFiscalResult;

    public function cancel(string $externalId): bool;
}
