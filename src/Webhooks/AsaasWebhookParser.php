<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Webhooks;

use Notfy\HubFinancial\Contracts\WebhookHandler;
use Notfy\HubFinancial\Domain\Enums\PaymentEventType;
use Notfy\HubFinancial\Support\ApiPayload;
use Notfy\HubFinancial\Support\GatewayAccount;

final class AsaasWebhookParser implements WebhookHandler
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
     * @param  array<string, string>  $headers
     */
    public function verify(string $rawBody, array $headers, GatewayAccount $account): bool
    {
        $token = $headers['asaas-access-token'] ?? $headers['Asaas-Access-Token'] ?? null;

        return $this->isValid($token, $account->webhookSecret ?? '');
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

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload): PaymentEvent
    {
        $event = $this->parse($payload);
        $payment = ApiPayload::toAssocArray($payload['payment'] ?? null);
        $value = ApiPayload::toFloat($payment['value'] ?? null);
        $fee = ApiPayload::toFloat($payment['fee'] ?? null);
        $paidAt = ApiPayload::optionalString($payment['confirmedDate'] ?? $payment['paymentDate'] ?? null);
        $chargeId = $event->paymentId ?? '';
        $eventId = $chargeId !== '' ? "{$chargeId}:{$event->type}" : $event->type;

        return new PaymentEvent(
            type: $this->mapEventType($event->type),
            chargeId: $chargeId,
            externalReference: $event->externalReference,
            amountPaidCents: $value > 0.0 ? (int) round($value * 100) : null,
            feeCents: $fee > 0.0 ? (int) round($fee * 100) : null,
            paidAt: $paidAt,
            provider: 'asaas',
            eventId: $eventId,
            raw: $payload,
        );
    }

    private function mapEventType(string $asaasEvent): PaymentEventType
    {
        return match ($asaasEvent) {
            'PAYMENT_CREATED' => PaymentEventType::Created,
            'PAYMENT_CONFIRMED' => PaymentEventType::Confirmed,
            'PAYMENT_RECEIVED' => PaymentEventType::Received,
            'PAYMENT_OVERDUE' => PaymentEventType::Overdue,
            'PAYMENT_REFUNDED', 'PAYMENT_REFUND_IN_PROGRESS' => PaymentEventType::Refunded,
            'PAYMENT_CHARGEBACK_DISPUTE', 'PAYMENT_CHARGEBACK_REQUESTED' => PaymentEventType::Chargeback,
            'PAYMENT_DELETED' => PaymentEventType::Cancelled,
            'PAYMENT_UPDATED' => PaymentEventType::Updated,
            default => PaymentEventType::Unknown,
        };
    }
}
