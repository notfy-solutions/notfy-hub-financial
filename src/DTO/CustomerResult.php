<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

final class CustomerResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $externalId,
        public readonly array $raw = [],
    ) {}
}
