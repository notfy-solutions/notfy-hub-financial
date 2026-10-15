<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use Closure;
use InvalidArgumentException;
use Notfy\HubFinancial\Contracts\BillingGateway;

/**
 * Constrói drivers de gateway a partir de um GatewayAccount. Suporta registro
 * dinâmico via extend() — nenhum driver é hardcoded aqui, então adicionar um
 * novo provedor não exige editar o pacote.
 *
 * Credenciais podem vir de env, do banco (por tenant) ou ser passadas
 * diretamente — o pacote recebe o GatewayAccount já montado.
 */
final class GatewayManager
{
    /** @var array<string, Closure(GatewayAccount): BillingGateway> */
    private array $drivers = [];

    /**
     * Registra a factory de um provedor. Chamado pelo ServiceProvider para
     * Asaas e Stripe, e por pacotes externos para provedores adicionais.
     *
     * @param  Closure(GatewayAccount): BillingGateway  $factory
     */
    public function extend(string $provider, Closure $factory): void
    {
        $this->drivers[$provider] = $factory;
    }

    /**
     * Constrói e retorna um driver para a conta fornecida.
     * As credenciais no GatewayAccount podem ter vindo de qualquer fonte
     * (config, banco, BYO key) — este método não distingue a origem.
     */
    public function forAccount(GatewayAccount $account): BillingGateway
    {
        if (! isset($this->drivers[$account->provider])) {
            throw new InvalidArgumentException(
                "Provedor '{$account->provider}' não registrado no GatewayManager. Use extend() para registrá-lo."
            );
        }

        return ($this->drivers[$account->provider])($account);
    }

    public function hasDriver(string $provider): bool
    {
        return isset($this->drivers[$provider]);
    }
}
