<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Domain\Enums;

enum Capability: string
{
    case BankSlip = 'bank_slip';
    case Pix = 'pix';
    case Card = 'card';
    case HybridBoletoPix = 'hybrid_boleto_pix';
    case Subscription = 'subscription';
    case Split = 'split';
    case SubAccounts = 'sub_accounts';
    case Statement = 'statement';
    case OwnBankAccount = 'own_bank_account';
    case CnabRemittance = 'cnab_remittance';
    case Checkout = 'checkout';
    case BillingPortal = 'billing_portal';
}
