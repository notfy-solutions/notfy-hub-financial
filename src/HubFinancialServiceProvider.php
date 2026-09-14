<?php

declare(strict_types=1);

namespace Notfy\HubFinancial;

use Illuminate\Support\ServiceProvider;
use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Gateways\Asaas\AsaasBillingGateway;
use Notfy\HubFinancial\Gateways\Asaas\AsaasHttpClient;
use Notfy\HubFinancial\Gateways\Stripe\StripeBillingGateway;
use Notfy\HubFinancial\Support\DefaultGatewayResolver;
use Notfy\HubFinancial\Support\GatewayResolver;
use Notfy\HubFinancial\Webhooks\AsaasWebhookParser;
use Notfy\HubFinancial\Webhooks\StripeWebhookParser;
use Stripe\StripeClient;

final class HubFinancialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hub-financial.php', 'hub-financial');

        // AsaasHttpClient construído com a chave/URL padrão da aplicação (conta
        // própria). Para cobrança com chave de terceiros (BYO key), a aplicação
        // consumidora deve instanciar AsaasHttpClient/AsaasBillingGateway
        // manualmente com a chave do cliente, em vez de resolver pelo container.
        $this->app->singleton(AsaasHttpClient::class, function ($app): AsaasHttpClient {
            $config = (array) $app['config']->get('hub-financial.gateways.asaas', []);

            return new AsaasHttpClient(
                apiKey: (string) ($config['api_key'] ?? ''),
                baseUrl: (string) ($config['base_url'] ?? 'https://api-sandbox.asaas.com/v3'),
            );
        });

        $this->app->singleton(
            AsaasBillingGateway::class,
            fn ($app) => new AsaasBillingGateway($app->make(AsaasHttpClient::class)),
        );

        // Mesma lógica de BYO key do Asaas acima, agora para a secret key do
        // Stripe. Usa o SDK oficial (StripeClient) em vez de um wrapper HTTP
        // próprio — o Stripe mantém e testa esse cliente, não faz sentido
        // reimplementar serialização/erros/retries na mão como fizemos para
        // o Asaas (que não tem SDK PHP oficial mantido).
        $this->app->singleton(StripeClient::class, function ($app): StripeClient {
            $config = (array) $app['config']->get('hub-financial.gateways.stripe', []);
            $clientConfig = ['api_key' => (string) ($config['secret_key'] ?? '')];
            $baseUrl = $config['base_url'] ?? null;

            if ($baseUrl !== null && $baseUrl !== '') {
                $clientConfig['api_base'] = (string) $baseUrl;
            }

            return new StripeClient($clientConfig);
        });

        $this->app->singleton(StripeBillingGateway::class, function ($app): StripeBillingGateway {
            $config = (array) $app['config']->get('hub-financial.gateways.stripe', []);

            return new StripeBillingGateway(
                client: $app->make(StripeClient::class),
                defaultCurrency: (string) ($config['default_currency'] ?? 'usd'),
            );
        });

        $this->app->singleton(GatewayResolver::class, function ($app): GatewayResolver {
            $config = (array) $app['config']->get('hub-financial', []);

            return new DefaultGatewayResolver(
                gateways: (array) ($config['gateways'] ?? []),
                routing: (array) ($config['routing'] ?? []),
            );
        });

        $this->app->singleton(BillingGateway::class, fn ($app) => $app->make(GatewayResolver::class)->resolve());
        $this->app->singleton(AsaasWebhookParser::class);
        $this->app->singleton(StripeWebhookParser::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/hub-financial.php' => config_path('hub-financial.php'),
            ], 'hub-financial-config');
        }
    }
}
