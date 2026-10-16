<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Credentials;

final readonly class MutualTls implements GatewayCredentials
{
    public function __construct(
        public string $certificate,
        public string $privateKey,
        public ?string $caCertificate = null,
    ) {}
}
