<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

final class ChargeInput
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $customerExternalId,
        public readonly int $amountCents,
        public readonly string $dueDate,            // Y-m-d
        public readonly string $billingType,        // BOLETO | PIX | CREDIT_CARD | UNDEFINED
        public readonly string $description,
        public readonly string $externalReference,  // id local (invoice/installment)
        public readonly ?string $cardToken = null,
        public readonly ?string $remoteIp = null,
        public readonly array $extra = [],
    ) {}
}
