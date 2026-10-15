<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Webhooks\PaymentEvent;

interface WebhookHandler
{
    /**
     * Verifica a autenticidade do webhook recebido.
     *
     * @param  array<string, string>  $headers  Headers HTTP da requisição (chaves em formato original).
     */
    public function verify(string $rawBody, array $headers, GatewayAccount $account): bool;

    /**
     * Normaliza o payload do webhook em um evento agnóstico de provedor.
     *
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload): PaymentEvent;
}
