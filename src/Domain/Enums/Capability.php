<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Domain\Enums;

/**
 * O que um gateway sabe fazer. Declarado por cada driver em
 * `DescribesGateway::capabilities()`, para a aplicação e o resolvedor
 * escolherem um provedor que atenda o pedido em vez de falhar no meio dele.
 */
enum Capability: string
{
    case BankSlip = 'bank_slip';
    case Pix = 'pix';
    case Card = 'card';
    case HybridBoletoPix = 'hybrid_boleto_pix';
    case Ach = 'ach';
    case SepaDebit = 'sepa_debit';
    case BankTransfer = 'bank_transfer';
    case Wallet = 'wallet';
    case Subscription = 'subscription';
    case Split = 'split';
    case SubAccounts = 'sub_accounts';
    case Statement = 'statement';
    case OwnBankAccount = 'own_bank_account';
    case CnabRemittance = 'cnab_remittance';
    case OpenBanking = 'open_banking';
    case Checkout = 'checkout';
    case BillingPortal = 'billing_portal';
    case MultiCurrency = 'multi_currency';
    case Refund = 'refund';
    case Webhook = 'webhook';
}
