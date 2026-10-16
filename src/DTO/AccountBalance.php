<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

final class AccountBalance
{
    public function __construct(
        public readonly int $availableCents,
        public readonly string $currency,
        public readonly ?int $pendingCents = null,
    ) {}
}
