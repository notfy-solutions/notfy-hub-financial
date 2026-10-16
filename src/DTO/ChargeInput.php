<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

use Notfy\HubFinancial\Domain\Enums\PaymentMethod;

final class ChargeInput
{
    /**
     * @param  string|null  $currency  ISO 4217 (BRL, USD, EUR...); null deixa o gateway usar a moeda do país.
     * @param  array<string, mixed>  $extra  Opções específicas do provedor.
     */
    public function __construct(
        public readonly string $customerExternalId,
        public readonly int $amountCents,
        public readonly string $dueDate,            // Y-m-d
        public readonly PaymentMethod $paymentMethod,
        public readonly string $description,
        public readonly string $externalReference,  // id local (invoice/installment)
        public readonly ?string $cardToken = null,
        public readonly ?string $remoteIp = null,
        public readonly array $extra = [],
        public readonly ?string $currency = null,
    ) {}
}
