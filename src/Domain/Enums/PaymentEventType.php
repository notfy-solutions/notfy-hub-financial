<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Domain\Enums;

enum PaymentEventType: string
{
    case Created = 'CREATED';
    case Confirmed = 'CONFIRMED';
    case Received = 'RECEIVED';
    case Overdue = 'OVERDUE';
    case Refunded = 'REFUNDED';
    case Chargeback = 'CHARGEBACK';
    case Cancelled = 'CANCELLED';
    case Updated = 'UPDATED';
    case Unknown = 'UNKNOWN';
}
