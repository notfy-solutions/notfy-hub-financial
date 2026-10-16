<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Events;

use Notfy\HubFinancial\Webhooks\PaymentEvent;

/** Disparado uma única vez por evento de webhook válido (já deduplicado). */
final readonly class PaymentEventReceived
{
    public function __construct(public PaymentEvent $event) {}
}
