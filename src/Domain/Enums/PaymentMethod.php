<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Domain\Enums;

/**
 * Meios de pagamento universais. Cada gateway declara quais suporta
 * (`DescribesGateway::supportedPaymentMethods()`); os locais (Boleto, Pix)
 * convivem com os internacionais (cartão, débito em conta ACH/SEPA).
 */
enum PaymentMethod: string
{
    case Boleto = 'BOLETO';
    case Pix = 'PIX';
    case Card = 'CREDIT_CARD';
    case Hybrid = 'BOLETO_PIX';
    case Ach = 'ACH';
    case SepaDebit = 'SEPA_DEBIT';
    case BankTransfer = 'BANK_TRANSFER';
    case Wallet = 'WALLET';
    case Undefined = 'UNDEFINED';
}
