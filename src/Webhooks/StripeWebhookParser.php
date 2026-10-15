<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Webhooks;

use Notfy\HubFinancial\Contracts\WebhookHandler;
use Notfy\HubFinancial\Domain\Enums\PaymentEventType;
use Notfy\HubFinancial\Support\ApiPayload;
use Notfy\HubFinancial\Support\GatewayAccount;
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
final class StripeWebhookParser implements WebhookHandler
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

    public function verify(string $rawBody, array $headers, GatewayAccount $account): bool
    {
        $signatureHeader = $headers['Stripe-Signature'] ?? $headers['stripe-signature'] ?? null;

        return $this->isValid($rawBody, $signatureHeader, $account->webhookSecret ?? '');
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

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload): PaymentEvent
    {
        $event = $this->parse($payload);
        $data = ApiPayload::toAssocArray($payload['data'] ?? null);
        $object = ApiPayload::toAssocArray($data['object'] ?? null);
        $amountRaw = ApiPayload::toInt($object['amount'] ?? null);
        $createdAt = ApiPayload::toInt($payload['created'] ?? null);

        return new PaymentEvent(
            type: $this->mapEventType($event->type),
            chargeId: $event->objectId ?? '',
            externalReference: $event->externalReference,
            amountPaidCents: $amountRaw > 0 ? $amountRaw : null,
            feeCents: null,
            paidAt: $createdAt > 0 ? gmdate('Y-m-d H:i:s', $createdAt) : null,
            provider: 'stripe',
            eventId: ApiPayload::stringOrDefault($payload['id'] ?? null, ''),
            raw: $payload,
        );
    }

    private function mapEventType(string $stripeEvent): PaymentEventType
    {
        return match ($stripeEvent) {
            'payment_intent.created' => PaymentEventType::Created,
            'payment_intent.succeeded', 'charge.succeeded' => PaymentEventType::Received,
            'payment_intent.payment_failed', 'charge.failed' => PaymentEventType::Overdue,
            'payment_intent.canceled' => PaymentEventType::Cancelled,
            'charge.refunded', 'charge.refund.updated' => PaymentEventType::Refunded,
            'charge.dispute.created', 'charge.dispute.funds_withdrawn' => PaymentEventType::Chargeback,
            'payment_intent.processing', 'payment_intent.requires_action' => PaymentEventType::Updated,
            default => PaymentEventType::Unknown,
        };
    }
}
