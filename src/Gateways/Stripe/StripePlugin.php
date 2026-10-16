<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Stripe;

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Contracts\GatewayPlugin;
use Notfy\HubFinancial\Contracts\ProvidesWebhookHandler;
use Notfy\HubFinancial\Contracts\WebhookHandler;
use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Support\ApiPayload;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Webhooks\StripeWebhookParser;
use Stripe\StripeClient;

final class StripePlugin implements GatewayPlugin, ProvidesWebhookHandler
{
    private const FALLBACK_CURRENCY = 'usd';

    public function name(): string
    {
        return 'stripe';
    }

    public function webhookHandler(): WebhookHandler
    {
        return new StripeWebhookParser;
    }

    /**
     * Opções da conta: `default_currency` (padrão usd) e `base_url` (só para
     * apontar a um servidor de testes; o SDK já usa a API oficial).
     */
    public function build(GatewayAccount $account): BillingGateway
    {
        $key = $account->credentials instanceof ApiKey ? $account->credentials->key : '';
        $baseUrl = ApiPayload::stringOrDefault($account->options['base_url'] ?? null, '');
        $currency = ApiPayload::stringOrDefault($account->options['default_currency'] ?? null, self::FALLBACK_CURRENCY);

        $config = ['api_key' => $key];
        if ($baseUrl !== '') {
            $config['api_base'] = $baseUrl;
        }

        return new StripeBillingGateway(new StripeClient($config), $currency);
    }
}
