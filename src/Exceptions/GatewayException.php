<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Exceptions;

use RuntimeException;

class GatewayException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly array $payload = [],
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
