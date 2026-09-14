<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Webhooks;

use Notfy\HubFinancial\Support\ApiPayload;

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
        $payment = ApiPayload::toAssocArray($payload['payment'] ?? null);

        return new AsaasWebhookEvent(
            type: ApiPayload::stringOrDefault($payload['event'] ?? null, 'UNKNOWN'),
            paymentId: ApiPayload::optionalString($payment['id'] ?? null),
            externalReference: ApiPayload::optionalString($payment['externalReference'] ?? null),
            status: ApiPayload::optionalString($payment['status'] ?? null),
            rawPayload: $payload,
        );
    }
}
