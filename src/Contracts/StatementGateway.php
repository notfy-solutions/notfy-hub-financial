<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

use Notfy\HubFinancial\DTO\AccountBalance;
use Notfy\HubFinancial\DTO\StatementEntry;
use Notfy\HubFinancial\DTO\StatementQuery;

/**
 * Extrato e saldo de uma conta bancária. Implementado por drivers de banco
 * (Open Banking, API de banco, Plaid, OFX...) e usado pela conciliação, que
 * trabalha só com `StatementEntry` e não conhece o provedor.
 */
interface StatementGateway
{
    /**
     * @return list<StatementEntry>
     */
    public function fetchStatement(StatementQuery $query): array;

    public function fetchBalance(?string $accountId = null): AccountBalance;
}
