<?php

declare(strict_types=1);

namespace Notfy\HubFinancial;

use Illuminate\Support\ServiceProvider;
use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Gateways\Asaas\AsaasBillingGateway;
use Notfy\HubFinancial\Gateways\Asaas\AsaasEnvironment;
use Notfy\HubFinancial\Gateways\Asaas\AsaasHttpClient;
use Notfy\HubFinancial\Gateways\Stripe\StripeBillingGateway;
use Notfy\HubFinancial\Support\ApiPayload;
use Notfy\HubFinancial\Support\DefaultGatewayResolver;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Support\GatewayManager;
use Notfy\HubFinancial\Support\GatewayResolver;
use Notfy\HubFinancial\Webhooks\AsaasWebhookParser;
use Notfy\HubFinancial\Webhooks\StripeWebhookParser;
use Stripe\StripeClient;

final class HubFinancialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hub-financial.php', 'hub-financial');

        // ─── Asaas ───────────────────────────────────────────────────────────
        // AsaasHttpClient construído com a chave/URL padrão da aplicação (conta
        // própria). Para cobrança com chave de terceiros (BYO key), use
        // GatewayManager::forAccount() passando um GatewayAccount com as
        // credenciais do cliente em vez de resolver pelo container.
        $this->app->singleton(AsaasHttpClient::class, function ($app): AsaasHttpClient {
            $config = (array) $app['config']->get('hub-financial.gateways.asaas', []);

            return new AsaasHttpClient(
                apiKey: (string) ($config['api_key'] ?? ''),
                baseUrl: AsaasEnvironment::fromConfig($config['environment'] ?? null)->baseUrl(),
            );
        });

        $this->app->singleton(
            AsaasBillingGateway::class,
            fn ($app) => new AsaasBillingGateway($app->make(AsaasHttpClient::class)),
        );

        // ─── Stripe ──────────────────────────────────────────────────────────
        // Usa o SDK oficial (StripeClient) — o Stripe mantém e testa esse
        // cliente, não faz sentido reimplementar serialização/erros/retries.
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

        // ─── GatewayResolver (backward compat) ───────────────────────────────
        $this->app->singleton(GatewayResolver::class, function ($app): GatewayResolver {
            $config = (array) $app['config']->get('hub-financial', []);

            return new DefaultGatewayResolver(
                gateways: (array) ($config['gateways'] ?? []),
                routing: (array) ($config['routing'] ?? []),
            );
        });

        $this->app->singleton(BillingGateway::class, fn ($app) => $app->make(GatewayResolver::class)->resolve());

        // ─── GatewayManager ──────────────────────────────────────────────────
        // Permite construir drivers a partir de um GatewayAccount com
        // credenciais de qualquer fonte (env, banco, BYO key).
        $this->app->singleton(GatewayManager::class, function ($app): GatewayManager {
            $manager = new GatewayManager;

            $manager->extend('asaas', function (GatewayAccount $account) use ($app): AsaasBillingGateway {
                $key = $account->credentials instanceof ApiKey ? $account->credentials->key : '';
                $environment = AsaasEnvironment::fromConfig($account->options['environment'] ?? null);

                return new AsaasBillingGateway(new AsaasHttpClient($key, $environment->baseUrl()));
            });

            $manager->extend('stripe', function (GatewayAccount $account) use ($app): StripeBillingGateway {
                $key = $account->credentials instanceof ApiKey ? $account->credentials->key : '';
                $clientConfig = ['api_key' => $key];
                $baseUrl = ApiPayload::stringOrDefault($account->options['base_url'] ?? null, '');

                if ($baseUrl !== '') {
                    $clientConfig['api_base'] = $baseUrl;
                }

                $currency = ApiPayload::stringOrDefault(
                    $account->options['default_currency'] ?? null,
                    ApiPayload::stringOrDefault(
                        $app['config']->get('hub-financial.gateways.stripe.default_currency'),
                        'usd'
                    )
                );

                return new StripeBillingGateway(new StripeClient($clientConfig), $currency);
            });

            return $manager;
        });

        // ─── Webhook parsers ─────────────────────────────────────────────────
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
