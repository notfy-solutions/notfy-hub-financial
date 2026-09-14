<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Webhooks;

use Notfy\HubFinancial\Support\ApiPayload;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * Valida e normaliza webhooks recebidos do Stripe. A verificação de
 * assinatura delega para `Stripe\Webhook::constructEvent()` (SDK oficial):
 * diferente do Asaas (token estático via header), o Stripe assina cada
 * entrega com HMAC-SHA256 sobre "{timestamp}.{corpo}" com janela de
 * tolerância contra replay — lógica mantida pelo próprio Stripe, não faz
 * sentido reimplementar isso na mão.
 *
 * `isValid()` recebe o corpo bruto (string, como veio na requisição, nunca
 * o array já decodificado) e o header `Stripe-Signature`.
 *
 * @see https://docs.stripe.com/webhooks#verify-manually
 */
final class StripeWebhookParser
{
    public function isValid(
        string $payload,
        ?string $signatureHeader,
        string $webhookSecret,
        int $toleranceSeconds = Webhook::DEFAULT_TOLERANCE,
    ): bool {
        if ($signatureHeader === null || $signatureHeader === '' || $webhookSecret === '') {
            return false;
        }

        try {
            Webhook::constructEvent($payload, $signatureHeader, $webhookSecret, $toleranceSeconds);

            return true;
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload): StripeWebhookEvent
    {
        $data = ApiPayload::toAssocArray($payload['data'] ?? null);
        $object = ApiPayload::toAssocArray($data['object'] ?? null);
        $metadata = ApiPayload::toAssocArray($object['metadata'] ?? null);

        return new StripeWebhookEvent(
            type: ApiPayload::stringOrDefault($payload['type'] ?? null, 'unknown'),
            objectId: ApiPayload::optionalString($object['id'] ?? null),
            externalReference: ApiPayload::optionalString($metadata['external_reference'] ?? null),
            status: ApiPayload::optionalString($object['status'] ?? null),
            rawPayload: $payload,
        );
    }
}
