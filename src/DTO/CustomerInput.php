<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

final class CustomerInput
{
    /**
     * @param  string  $document  Identificação fiscal (CPF/CNPJ, EIN, VAT...); vazio se o país não exige.
     * @param  string|null  $country  ISO 3166-1 alfa-2.
     * @param  string|null  $documentType  Qualificador do documento (ex.: cpf, cnpj, ein, vat).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $document,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $externalReference = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $addressNumber = null,
        public readonly ?string $country = null,
        public readonly ?string $documentType = null,
        public readonly ?string $addressLine = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
    ) {}
}
