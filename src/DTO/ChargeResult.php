<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

use Notfy\HubFinancial\Domain\Enums\ChargeStatus;

final class ChargeResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $externalId,
        public readonly ChargeStatus $status,
        public readonly ?string $invoiceUrl = null,
        public readonly ?string $bankSlipUrl = null,
        public readonly ?string $pixCopyPaste = null,
        public readonly array $raw = [],
    ) {}
}
