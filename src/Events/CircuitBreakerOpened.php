<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Events;

final readonly class CircuitBreakerOpened
{
    public function __construct(public string $gateway) {}
}
