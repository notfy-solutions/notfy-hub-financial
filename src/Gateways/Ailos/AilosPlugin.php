<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Ailos;

use InvalidArgumentException;
use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Contracts\GatewayPlugin;
use Notfy\HubFinancial\Credentials\OAuth2;
use Notfy\HubFinancial\Support\ApiPayload;
use Notfy\HubFinancial\Support\GatewayAccount;

/**
 * Cobrança bancária (boleto 085) do Sistema AILOS. Hoje só atende o Brasil.
 * Não há webhook: a conciliação é por arquivo de retorno.
 */
final class AilosPlugin implements GatewayPlugin
{
    public function name(): string
    {
        return 'ailos';
    }

    /**
     * Credencial: OAuth2 (client_credentials do WSO2). Opções da conta:
     * `convenio` (obrigatória, número do convênio de cobrança), `carteira`
     * (padrão 1), `environment` (`production`|`sandbox`, padrão sandbox) e
     * `base_url` (obrigatória em produção).
     */
    public function build(GatewayAccount $account): BillingGateway
    {
        if (! $account->credentials instanceof OAuth2) {
            throw new InvalidArgumentException('O AILOS exige credenciais OAuth2.');
        }

        $convenio = preg_replace('/\D/', '', ApiPayload::stringOrDefault($account->options['convenio'] ?? null, ''));
        if ($convenio === null || $convenio === '') {
            throw new InvalidArgumentException("A conta AILOS exige a opção 'convenio'.");
        }

        $environment = AilosEnvironment::fromConfig($account->options['environment'] ?? null);
        $baseUrl = ApiPayload::stringOrDefault($account->options['base_url'] ?? null, $environment->defaultBaseUrl() ?? '');

        return new AilosBillingGateway(
            new AilosHttpClient($account->credentials, $baseUrl),
            $convenio,
            max(1, ApiPayload::toInt($account->options['carteira'] ?? null, 1)),
        );
    }
}
