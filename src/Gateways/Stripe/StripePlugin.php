<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Stripe;

use Illuminate\Contracts\Container\Container;
use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Contracts\GatewayPlugin;
use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Support\ApiPayload;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Support\HubFinancialConfig;
use Notfy\HubFinancial\Webhooks\StripeWebhookParser;
use Stripe\StripeClient;

/**
 * Usa o SDK oficial (StripeClient): o Stripe mantém e testa esse cliente, não
 * faz sentido reimplementar serialização, erros e retries.
 */
final class StripePlugin implements GatewayPlugin
{
    private const FALLBACK_CURRENCY = 'usd';

    public function __construct(private readonly Container $container) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function register(): void
    {
        $this->container->singleton(StripeClient::class, function (Container $app): StripeClient {
            $config = $app->make(HubFinancialConfig::class);

            return $this->client(
                $config->gatewayOption('stripe', 'secret_key'),
                $config->gatewayOption('stripe', 'base_url'),
            );
        });

        $this->container->singleton(
            StripeBillingGateway::class,
            fn (Container $app): StripeBillingGateway => new StripeBillingGateway(
                client: $app->make(StripeClient::class),
                defaultCurrency: $this->defaultCurrency(),
            ),
        );

        $this->container->singleton(StripeWebhookParser::class);
    }

    public function build(GatewayAccount $account): BillingGateway
    {
        $key = $account->credentials instanceof ApiKey ? $account->credentials->key : '';
        $baseUrl = ApiPayload::stringOrDefault($account->options['base_url'] ?? null, '');
        $currency = ApiPayload::stringOrDefault($account->options['default_currency'] ?? null, $this->defaultCurrency());

        return new StripeBillingGateway($this->client($key, $baseUrl), $currency);
    }

    private function client(string $apiKey, string $baseUrl): StripeClient
    {
        $config = ['api_key' => $apiKey];

        if ($baseUrl !== '') {
            $config['api_base'] = $baseUrl;
        }

        return new StripeClient($config);
    }

    private function defaultCurrency(): string
    {
        $currency = $this->container->make(HubFinancialConfig::class)->gatewayOption('stripe', 'default_currency');

        return $currency !== '' ? $currency : self::FALLBACK_CURRENCY;
    }
}
