<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

use Notfy\HubFinancial\Domain\Enums\NextActionType;

/**
 * Passo pendente de uma cobrança assíncrona (redirect, 3DS, voucher, QR...),
 * comum a métodos como iDEAL, OXXO, UPI e Bancontact.
 */
final class NextAction
{
    /**
     * @param  array<string, mixed>  $data  Dados específicos (instruções, código, expiração).
     */
    public function __construct(
        public readonly NextActionType $type,
        public readonly ?string $url = null,
        public readonly array $data = [],
    ) {}
}
