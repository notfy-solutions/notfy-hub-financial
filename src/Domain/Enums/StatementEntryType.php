<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Domain\Enums;

enum StatementEntryType: string
{
    case Credit = 'CREDIT';
    case Debit = 'DEBIT';
}
