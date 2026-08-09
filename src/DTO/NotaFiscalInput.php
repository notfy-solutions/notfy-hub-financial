<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

final class NotaFiscalInput
{
    /**
     * Pelo menos um entre paymentExternalId, installmentExternalId ou customerExternalId
     * é obrigatório (regra da API do Asaas). serviceValueCents é sempre em centavos.
     */
    public function __construct(
        public readonly string $serviceDescription,
        public readonly string $observations,
        public readonly int $serviceValueCents,
        public readonly string $effectiveDate,          // Y-m-d
        public readonly string $municipalServiceName,
        public readonly NotaFiscalTaxes $taxes,
        public readonly ?string $paymentExternalId = null,
        public readonly ?string $installmentExternalId = null,
        public readonly ?string $customerExternalId = null,
        public readonly ?string $municipalServiceId = null,
        public readonly ?string $municipalServiceCode = null,
        public readonly float $deductions = 0.0,
        public readonly ?string $externalReference = null,
        public readonly bool $updatePayment = false,
    ) {}
}
