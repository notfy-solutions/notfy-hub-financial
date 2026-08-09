<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

final class CustomerInput
{
    public function __construct(
        public readonly string $name,
        public readonly string $document,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $externalReference = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $addressNumber = null,
    ) {}
}
