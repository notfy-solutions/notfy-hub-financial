<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Credentials;

final readonly class ApiKey implements GatewayCredentials
{
    public function __construct(
        public string $key,
    ) {}
}
