<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Domain\Enums;

/** O que o pagador/aplicação precisa fazer para concluir a cobrança. */
enum NextActionType: string
{
    case Redirect = 'redirect';
    case ThreeDSecure = 'three_d_secure';
    case Voucher = 'voucher';
    case QrCode = 'qr_code';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';
}
