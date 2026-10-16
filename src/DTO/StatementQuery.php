<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

final class StatementQuery
{
    public function __construct(
        public readonly string $from,   // Y-m-d
        public readonly string $to,     // Y-m-d
        public readonly ?string $accountId = null,
    ) {}
}
