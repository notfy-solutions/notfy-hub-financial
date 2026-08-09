<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Webhooks;

final class AsaasWebhookEvent
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public readonly string $type,               // ex: PAYMENT_CONFIRMED
        public readonly ?string $paymentId,
        public readonly ?string $externalReference,
        public readonly ?string $status,
        public readonly array $rawPayload,
    ) {}

    public function isPaymentEvent(): bool
    {
        return str_starts_with($this->type, 'PAYMENT_');
    }
}
