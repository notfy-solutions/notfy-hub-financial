<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Asaas;

use Illuminate\Contracts\Container\Container;
use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Contracts\GatewayPlugin;
use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Support\HubFinancialConfig;
use Notfy\HubFinancial\Webhooks\AsaasWebhookParser;

final class AsaasPlugin implements GatewayPlugin
{
    public function __construct(private readonly Container $container) {}

    public function name(): string
    {
        return 'asaas';
    }

    public function register(): void
    {
        $this->container->singleton(AsaasHttpClient::class, function (Container $app): AsaasHttpClient {
            $config = $app->make(HubFinancialConfig::class);

            return new AsaasHttpClient(
                apiKey: $config->gatewayOption('asaas', 'api_key'),
                baseUrl: AsaasEnvironment::fromConfig($config->gatewayOption('asaas', 'environment'))->baseUrl(),
            );
        });

        $this->container->singleton(
            AsaasBillingGateway::class,
            fn (Container $app): AsaasBillingGateway => new AsaasBillingGateway($app->make(AsaasHttpClient::class)),
        );

        $this->container->singleton(AsaasWebhookParser::class);
    }

    public function build(GatewayAccount $account): BillingGateway
    {
        $key = $account->credentials instanceof ApiKey ? $account->credentials->key : '';
        $environment = AsaasEnvironment::fromConfig($account->options['environment'] ?? null);

        return new AsaasBillingGateway(new AsaasHttpClient($key, $environment->baseUrl()));
    }
}
