<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use InvalidArgumentException;
use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\Exceptions\UnsupportedGatewayRequestException;

/**
 * Escolhe o gateway certo entre as contas que a aplicação entrega.
 *
 * O pacote não lê credencial de lugar nenhum: quem usa decide se vêm de env,
 * banco (por tenant) ou cofre, e passa tudo pronto em GatewayAccount.
 * Nada é cacheado entre chamadas, então é seguro em Octane/multi-tenant.
 */
final readonly class GatewayResolver
{
    /**
     * @param  array<string, array<string, string>>  $routing  país|'*' => tipo|'default' => provedor
     */
    public function __construct(
        private GatewayManager $manager,
        private array $routing = [],
    ) {}

    /**
     * @param  list<GatewayAccount>  $accounts
     *
     * @throws InvalidArgumentException sem contas
     * @throws UnsupportedGatewayRequestException nenhuma conta atende o pedido
     */
    public function resolve(
        array $accounts,
        ?GatewayContext $context = null,
        ?PaymentMethod $method = null,
        ?Capability $capability = null,
    ): BillingGateway {
        if ($accounts === []) {
            throw new InvalidArgumentException('Nenhuma conta de gateway informada.');
        }

        $context ??= DefaultGatewayContext::make();
        $failure = null;

        foreach ($this->ordered($accounts, $context) as $account) {
            $gateway = $this->manager->forAccount($account);

            try {
                GatewayRequirements::assertServes($gateway, $context, $method, $capability);

                return $gateway;
            } catch (UnsupportedGatewayRequestException $exception) {
                $failure ??= $exception;
            }
        }

        throw $failure ?? new InvalidArgumentException('Nenhuma conta de gateway informada.');
    }

    /**
     * Alguma das contas atende ao país? (para decidir se oferece cobrança)
     *
     * @param  list<GatewayAccount>  $accounts
     */
    public function supportsCountry(array $accounts, string $country): bool
    {
        foreach ($accounts as $account) {
            if (GatewayRequirements::servesCountry($this->manager->forAccount($account), $country)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Todas as contas que atendem ao contexto, na ordem de prioridade.
     *
     * @param  list<GatewayAccount>  $accounts
     * @return list<BillingGateway>
     */
    public function available(array $accounts, GatewayContext $context): array
    {
        $gateways = [];

        foreach ($this->ordered($accounts, $context) as $account) {
            $gateway = $this->manager->forAccount($account);

            try {
                GatewayRequirements::assertServes($gateway, $context);
                $gateways[] = $gateway;
            } catch (UnsupportedGatewayRequestException) {
                continue;
            }
        }

        return $gateways;
    }

    /**
     * Preferida pelo contexto, depois a do roteamento, depois as demais (a ordem recebida).
     *
     * @param  list<GatewayAccount>  $accounts
     * @return list<GatewayAccount>
     */
    private function ordered(array $accounts, GatewayContext $context): array
    {
        $routed = $this->routing[$context->getCountry()] ?? $this->routing['*'] ?? [];
        $type = $context->getPaymentType();
        $priority = array_values(array_filter([
            $context->getPreferredGateway(),
            $type !== 'any' ? ($routed[$type] ?? null) : null,
            $routed['default'] ?? null,
        ], static fn (?string $provider): bool => $provider !== null));

        $ordered = [];
        foreach ($priority as $provider) {
            foreach ($accounts as $account) {
                if ($account->provider === $provider && ! in_array($account, $ordered, true)) {
                    $ordered[] = $account;
                }
            }
        }

        foreach ($accounts as $account) {
            if (! in_array($account, $ordered, true)) {
                $ordered[] = $account;
            }
        }

        return $ordered;
    }
}
