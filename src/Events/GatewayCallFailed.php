<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Events;

use Throwable;

final readonly class GatewayCallFailed
{
    public function __construct(
        public string $gateway,
        public string $operation,
        public int $attempt,
        public bool $willRetry,
        public Throwable $exception,
    ) {}
}
