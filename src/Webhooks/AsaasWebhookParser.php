<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Webhooks;

final class AsaasWebhookParser
{
    /**
     * Valida o header `asaas-access-token` do request recebido contra o
     * authToken configurado no cadastro do webhook (painel/API Asaas).
     */
    public function isValid(?string $receivedToken, string $expectedToken): bool
    {
        if ($receivedToken === null || $receivedToken === '' || $expectedToken === '') {
            return false;
        }

        return hash_equals($expectedToken, $receivedToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload): AsaasWebhookEvent
    {
        $payment = (array) ($payload['payment'] ?? []);

        return new AsaasWebhookEvent(
            type: (string) ($payload['event'] ?? 'UNKNOWN'),
            paymentId: isset($payment['id']) ? (string) $payment['id'] : null,
            externalReference: isset($payment['externalReference']) ? (string) $payment['externalReference'] : null,
            status: isset($payment['status']) ? (string) $payment['status'] : null,
            rawPayload: $payload,
        );
    }
}
