<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Domain\Enums;

enum ChargeStatus: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Received = 'RECEIVED';
    case Overdue = 'OVERDUE';
    case Refunded = 'REFUNDED';
    case Deleted = 'DELETED';
}
