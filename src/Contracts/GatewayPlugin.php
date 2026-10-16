<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

use Notfy\HubFinancial\Support\GatewayAccount;

/**
 * Ponto único de integração de um provedor de cobrança com o pacote.
 * Adicionar um provedor (ou país) é criar um plugin e listá-lo em
 * `hub-financial.plugins`, sem editar o ServiceProvider.
 *
 * Plugins não leem credenciais: recebem tudo em GatewayAccount.
 */
interface GatewayPlugin
{
    /** Chave do driver, igual a GatewayAccount::$provider. */
    public function name(): string;

    /** Constrói o gateway para uma conta, com credenciais de qualquer origem. */
    public function build(GatewayAccount $account): BillingGateway;
}
