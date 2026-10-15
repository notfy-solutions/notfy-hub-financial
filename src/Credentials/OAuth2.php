<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Credentials;

final readonly class OAuth2 implements GatewayCredentials
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $tokenUrl,
        public string $scope = '',
    ) {}
}
