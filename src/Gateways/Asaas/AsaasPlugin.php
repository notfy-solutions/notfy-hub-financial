<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Asaas;

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Contracts\GatewayPlugin;
use Notfy\HubFinancial\Contracts\ProvidesWebhookHandler;
use Notfy\HubFinancial\Contracts\WebhookHandler;
use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Webhooks\AsaasWebhookParser;

final class AsaasPlugin implements GatewayPlugin, ProvidesWebhookHandler
{
    public function name(): string
    {
        return 'asaas';
    }

    public function webhookHandler(): WebhookHandler
    {
        return new AsaasWebhookParser;
    }

    /**
     * Opções da conta: `environment` (`production`|`sandbox`, padrão sandbox).
     */
    public function build(GatewayAccount $account): BillingGateway
    {
        $key = $account->credentials instanceof ApiKey ? $account->credentials->key : '';
        $environment = AsaasEnvironment::fromConfig($account->options['environment'] ?? null);

        return new AsaasBillingGateway(new AsaasHttpClient($key, $environment->baseUrl()));
    }
}
