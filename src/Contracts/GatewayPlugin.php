<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

use Notfy\HubFinancial\Support\GatewayAccount;

/**
 * Ponto único de integração de um provedor de cobrança com o pacote. O
 * ServiceProvider conhece apenas esta interface: adicionar um provedor (ou
 * país) é criar um plugin e listá-lo em `hub-financial.plugins`, sem editar
 * o provider.
 */
interface GatewayPlugin
{
    /** Chave do driver, igual à usada em `hub-financial.gateways` e em GatewayAccount::$provider. */
    public function name(): string;

    /** Registra no container os bindings da conta padrão da aplicação (config). */
    public function register(): void;

    /** Constrói o gateway para uma conta com credenciais de qualquer origem (BYO key, banco por tenant). */
    public function build(GatewayAccount $account): BillingGateway;
}
