<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

final class NotaFiscalResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $status, // SCHEDULED | AUTHORIZED | PROCESSING_CANCELLATION | CANCELED | CANCELLATION_DENIED | ERROR
        public readonly ?string $number = null,
        public readonly ?string $validationCode = null,
        public readonly ?string $pdfUrl = null,
        public readonly ?string $xmlUrl = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
    ) {}
}
