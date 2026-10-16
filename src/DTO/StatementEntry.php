<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

use Notfy\HubFinancial\Domain\Enums\StatementEntryType;

/**
 * Lançamento de extrato agnóstico de banco/país. É a matéria-prima da
 * conciliação: independe de o extrato vir de OFX, CNAB, Open Banking ou API
 * de um provedor.
 */
final class StatementEntry
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly StatementEntryType $type,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly string $date,       // Y-m-d
        public readonly string $description,
        public readonly ?string $reference = null,
        public readonly array $raw = [],
    ) {}
}
